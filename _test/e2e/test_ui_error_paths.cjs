// Focused UI response regression tests. Node built-in Response performs real JSON parsing;
// only the browser DOM, fetch transport and storage are synthetic. No network is used.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const crypto = require('node:crypto');
const path = require('node:path');

const sourcePath = path.join(__dirname, '..', '..', 'script', 'AIChatChat.js');
const source = fs.readFileSync(sourcePath, 'utf8');
const sourceSha256 = crypto.createHash('sha256').update(source).digest('hex');
if (process.env.EXPECT_SOURCE_HASH) {
  assert.equal(sourceSha256, process.env.EXPECT_SOURCE_HASH, 'tested UI source hash');
}

class Element {
  constructor(tag='div') {
    this.tag = tag; this.children = []; this.attrs = {}; this.events = {}; this._text = ''; this._html = '';
    this.style = {removeProperty(k) { delete this[k]; }};
    const names = new Set();
    this.classList = {add: (...xs) => xs.forEach(x=>names.add(x)), contains: x=>names.has(x), toggle:x=>names.has(x)?names.delete(x):names.add(x)};
  }
  set innerHTML(v) { this._html=String(v); this._text=''; this.children=[]; }
  get innerHTML() { return this._html; }
  set textContent(v) { this._text=String(v ?? ''); this._html=''; this.children=[]; }
  get textContent() { return this._text+this.children.map(x=>x.textContent).join(''); }
  appendChild(x) { this.children.push(x); x.parent=this; return x; }
  setAttribute(k,v) { this.attrs[k]=String(v); }
  getAttribute(k) { return this.attrs[k] ?? null; }
  addEventListener(k,v) { this.events[k]=v; }
  focus() { this.focused=true; }
  scrollIntoView() {}
  remove() { if(this.parent) this.parent.children=this.parent.children.filter(x=>x!==this); }
  querySelectorAll(s) { return this.children.flatMap(x=>[(s[0]==='.'?x.classList.contains(s.slice(1)):x.tag===s)?x:null,...x.querySelectorAll(s)]).filter(Boolean); }
  querySelector(s) { return this.querySelectorAll(s)[0] ?? null; }
}
class Shadow extends Element {
  constructor() {
    super('shadow'); this.refs={};
    for(const s of ['textarea','.output','progress','.controls','.pagecontext','form','.delete-history']) this.refs[s]=new Element(s);
    this.refs['.pagecontext'].classList.add('off');
  }
  querySelector(s) { return this.refs[s] ?? super.querySelector(s); }
  querySelectorAll(s) { return Object.values(this.refs).flatMap(x=>x.querySelectorAll(s)); }
}
function makeEnvelope(outcome='ERROR',answer='The AI service is unavailable. Reference: 0123456789abcdef') {
  return {question:'interpreted synthetic question',answer,sources:[],meta:{v:1,outcome,correlationId:outcome==='ERROR'?'0123456789abcdef':'',conversationId:'synthetic-conversation',pendingId:'',options:[],feedback:false}};
}
function jsonResponse(value,status=200) { return new Response(JSON.stringify(value),{status,headers:{'Content-Type':'application/json'}}); }
function harness(responseFactory, initialHistory=null, localized={}, failStorage=false, initialState={}) {
  const storage=new Map(), logs=[], requests=[], timers=new Set(); let Chat;
  if(initialHistory) storage.set('ai-chat-history',JSON.stringify(initialHistory));
  if(initialState.conversation !== undefined) storage.set('ai-chat-conversation',initialState.conversation);
  if(initialState.pending !== undefined) storage.set('ai-chat-pending',initialState.pending);
  const fakeConsole={error:(...args)=>logs.push(args),warn:(...args)=>logs.push(args)};
  class HTMLElement extends Element { attachShadow() { return this.shadowRoot=new Shadow(); } }
  const JSINFO={id:'synthetic:start',plugin_aichat:{sectok:'SYNTHETIC_TOKEN'}};
  const LANG={plugins:{aichat:{error:'Sorry, something went wrong',...localized}}};
  const context=vm.createContext({
    HTMLElement,document:{createElement:t=>new Element(t)},window:{JSINFO,LANG,console:fakeConsole,customElements:{define:(n,c)=>Chat=c}},JSINFO,LANG,
    FormData,Response,DOMException,
    fetch:async (url,options)=>{requests.push(Object.fromEntries(options.body));return responseFactory();},
    sessionStorage:{getItem:k=>storage.get(k)??null,setItem:(k,v)=>{if(failStorage===true || failStorage===k) throw new Error('SYNTHETIC_STORAGE_MARKER');storage.set(k,v);},removeItem:k=>storage.delete(k)},
    setInterval:()=>{const t={};timers.add(t);return t;},clearInterval:t=>timers.delete(t),
    console:fakeConsole,
  });
  vm.runInContext(source,context,{filename:sourcePath});
  const chat=new Chat(); chat.connectedCallback();
  return {chat,storage,logs,requests,timers};
}
function allText(el) { return el.children.map(x=>x.textContent+x.children.map(y=>y.innerHTML).join('')).join('\n'); }
const check=(name,fn)=>({name,fn});
const tests=[];
for(const status of [502,503,504]) tests.push(check(`HTTP ${status} HTML explains service status`,async()=>{
  const h=harness(()=>new Response('<html>SYNTHETIC_PRIVATE_MARKER</html>',{status,headers:{'Content-Type':'text/html'}}));
  await h.chat.ask('original question'); const out=h.chat.shadowRoot.refs['.output']; const alert=out.children.at(-1);
  assert.match(allText(out),new RegExp(`HTTP ${status}`)); assert.equal(alert.attrs.role,'alert'); assert.equal(alert.textContent.includes('SYNTHETIC_PRIVATE_MARKER'),false);
  assert.equal(JSON.parse(h.storage.get('ai-chat-history') || '[]').length,0); assert.equal(h.requests.length,1); assert.equal(h.timers.size,0);
  assert.equal(h.chat.shadowRoot.refs.textarea.focused,true); assert.equal(h.chat.shadowRoot.refs.progress.style.display,'none');
  assert.equal(JSON.stringify(h.logs).includes('SYNTHETIC_PRIVATE_MARKER'),false);
}));
tests.push(check('HTTP 500 HTML uses a safe numeric HTTP failure',async()=>{
  const h=harness(()=>new Response('<html>SYNTHETIC_PRIVATE_MARKER</html>',{status:500})); await h.chat.ask('q');
  assert.match(allText(h.chat.shadowRoot.refs['.output']),/HTTP 500/); assert.equal(JSON.parse(h.storage.get('ai-chat-history') || '[]').length,0);
  assert.equal(JSON.stringify(h.logs).includes('SYNTHETIC_PRIVATE_MARKER'),false);
  assert.deepEqual(JSON.parse(JSON.stringify(h.logs)),[['AI Chat request failed',{category:'http',status:500}]]);
}));
tests.push(check('HTTP 200 HTML uses safe invalid-response wording',async()=>{
  const h=harness(()=>new Response('<html>SYNTHETIC_PRIVATE_MARKER</html>',{status:200})); await h.chat.ask('q');
  assert.match(allText(h.chat.shadowRoot.refs['.output']),/invalid response/i); assert.equal(JSON.stringify(h.logs).includes('SYNTHETIC_PRIVATE_MARKER'),false);
  assert.equal(JSON.parse(h.storage.get('ai-chat-history') || '[]').length,0);
}));
tests.push(check('valid JSON without metadata remains compatible with legacy replies',async()=>{
  const h=harness(()=>jsonResponse({question:'legacy question',answer:'<p>legacy answer</p>',sources:[]})); await h.chat.ask('q');
  assert.match(allText(h.chat.shadowRoot.refs['.output']),/legacy answer/); assert.equal(JSON.parse(h.storage.get('ai-chat-history')).length,1);
}));
tests.push(check('HTTP 502 JSON error text is ignored in favor of safe status wording',async()=>{
  const h=harness(()=>jsonResponse({error:'SYNTHETIC_PRIVATE_MARKER'},502)); await h.chat.ask('q'); const out=allText(h.chat.shadowRoot.refs['.output']);
  assert.match(out,/HTTP 502/); assert.equal(out.includes('SYNTHETIC_PRIVATE_MARKER'),false); assert.equal(JSON.parse(h.storage.get('ai-chat-history')||'[]').length,0);
}));
for(const [name,factory] of [
  ['malformed JSON',()=>new Response('{broken',{status:200,headers:{'Content-Type':'application/json'}})],
  ['JSON null',()=>jsonResponse(null)],
  ['wrong schema',()=>jsonResponse({error:'SYNTHETIC_PRIVATE_MARKER'})],
  ['invalid answer type',()=>jsonResponse({question:'q',answer:{bad:'x'},sources:[]})],
  ['invalid sources type',()=>jsonResponse({question:'q',answer:'x',sources:{}})],
]) tests.push(check(`${name} is rejected before conversation history`,async()=>{
  const h=harness(factory); await h.chat.ask('original question');
  assert.match(allText(h.chat.shadowRoot.refs['.output']),/invalid response/i); assert.equal(JSON.parse(h.storage.get('ai-chat-history') || '[]').length,0);
}));
tests.push(check('fetch rejection gets connection guidance without raw exception',async()=>{
  const h=harness(()=>Promise.reject(new TypeError('SYNTHETIC_PRIVATE_MARKER token=synthetic'))); await h.chat.ask('q');
  assert.match(allText(h.chat.shadowRoot.refs['.output']),/connection/i); assert.equal(JSON.stringify(h.logs).includes('SYNTHETIC_PRIVATE_MARKER'),false);
  assert.deepEqual(JSON.parse(JSON.stringify(h.logs)),[['AI Chat request failed',{category:'network',status:null}]]);
}));
tests.push(check('HTTP status wording uses the active translation when present',async()=>{
  const h=harness(()=>new Response('<html>no</html>',{status:504}),null,{error_timeout:'Translation example 504'}); await h.chat.ask('q');
  assert.match(allText(h.chat.shadowRoot.refs['.output']),/Translation example 504/);
}));
tests.push(check('abort is described as interrupted',async()=>{
  const h=harness(()=>Promise.reject(new DOMException('synthetic','AbortError'))); await h.chat.ask('q'); assert.match(allText(h.chat.shadowRoot.refs['.output']),/interrupted/i);
}));
tests.push(check('valid HTTP 403 CSRF JSON keeps its safe reference and is not stored',async()=>{
  const data=makeEnvelope('ERROR','Your session expired. Reference: 0123456789abcdef'); const h=harness(()=>jsonResponse(data,403));
  await h.chat.ask('original question'); assert.match(allText(h.chat.shadowRoot.refs['.output']),/Reference: 0123456789abcdef/);
  assert.equal(JSON.parse(h.storage.get('ai-chat-history') || '[]').length,0); assert.equal(h.requests.length,1);
  const out=h.chat.shadowRoot.refs['.output']; assert.equal(out.children.at(-1).attrs.role,'alert');
  assert.equal(out.children.find(x=>x.classList.contains('user')).textContent,'original question');
  assert.deepEqual(h.logs,[],'a validated backend ERROR already includes safe guidance and should not be logged as a client transport failure');
}));
tests.push(check('storage error gets local guidance and always releases the progress UI',async()=>{
  const originalHistory=[['prior question','prior answer',[],{outcome:'ANSWER'}]];
  const h=harness(()=>jsonResponse(makeEnvelope('ANSWER','grounded answer')),originalHistory,{},'ai-chat-pending',{conversation:'prior-conversation',pending:'prior-pending'});
  await h.chat.ask('q');
  assert.match(allText(h.chat.shadowRoot.refs['.output']),/could not be displayed or saved/i);
  assert.doesNotMatch(allText(h.chat.shadowRoot.refs['.output']),/connection|HTTP 50[0-9]/i);
  assert.equal(h.timers.size,0); assert.equal(h.chat.shadowRoot.refs.progress.style.display,'none');
  assert.deepEqual(JSON.parse(h.storage.get('ai-chat-history')),originalHistory,'partial storage failure must roll back the earlier history write');
  assert.equal(h.storage.get('ai-chat-conversation'),'prior-conversation');
  assert.equal(h.storage.get('ai-chat-pending'),'prior-pending');
  await h.chat.ask('retry');
  assert.deepEqual(JSON.parse(h.requests[1].history),originalHistory.map(row=>row.slice(0,2)),'failed local persistence must not leave an invisible model-history turn');
  assert.equal(h.requests[1].conversation,'prior-conversation','failed local persistence must not advance conversation state');
  assert.equal(h.requests[1].pending,'prior-pending','failed local persistence must not advance pending state');
}));
tests.push(check('render failure restores the original question and persisted model state',async()=>{
  const h=harness(()=>jsonResponse(makeEnvelope('ANSWER','<p>grounded answer</p>')));
  const displayMessage=h.chat.displayMessage.bind(h.chat);
  h.chat.displayMessage=(message,sources=null,meta=null,interactive=false)=>{
    if(sources!==null) throw new Error('SYNTHETIC_RENDER_MARKER');
    return displayMessage(message,sources,meta,interactive);
  };
  await h.chat.ask('original question');
  const users=h.chat.shadowRoot.refs['.output'].children.filter(x=>x.classList.contains('user'));
  assert.equal(users.at(-1).textContent,'original question');
  assert.equal(users.at(-1).title,'');
  assert.equal(JSON.parse(h.storage.get('ai-chat-history')||'[]').length,0);
  assert.equal(h.storage.get('ai-chat-conversation')??'','');
  assert.equal(h.requests.length,1);
  assert.equal(JSON.stringify(h.logs).includes('SYNTHETIC_RENDER_MARKER'),false);
}));
tests.push(check('ERROR JSON without a valid reference is rejected and cannot render its answer body',async()=>{
  for(const [status,correlationId] of [[200,''],[403,'not-a-reference'],[500,undefined]]) {
    const data=makeEnvelope('ERROR','<img src=x onerror=alert(1)> SYNTHETIC_PRIVATE_MARKER');
    if(correlationId===undefined) delete data.meta.correlationId; else data.meta.correlationId=correlationId;
    const originalHistory=[['prior question','prior answer',[],{outcome:'ANSWER'}]];
    const h=harness(()=>jsonResponse(data,status),originalHistory,{},false,{conversation:'prior-conversation',pending:'prior-pending'});
    await h.chat.ask('original question'); const output=allText(h.chat.shadowRoot.refs['.output']);
    assert.equal(output.includes('SYNTHETIC_PRIVATE_MARKER'),false,`HTTP ${status} must not render an unreferenced error answer`);
    assert.deepEqual(JSON.parse(h.storage.get('ai-chat-history')),originalHistory);
    assert.equal(h.storage.get('ai-chat-conversation'),'prior-conversation');
    assert.equal(h.storage.get('ai-chat-pending'),'prior-pending');
    assert.equal(h.requests[0].conversation,'prior-conversation');
    assert.equal(h.requests[0].pending,'prior-pending');
    assert.equal(h.chat.shadowRoot.refs['.output'].children.filter(x=>x.classList.contains('user')).at(-1).textContent,'original question');
    assert.equal(h.requests[0].question,'original question');
    if(status===200) assert.match(output,/invalid response/i);
    else assert.match(output,new RegExp(`HTTP ${status}`));
  }
}));
tests.push(check('valid ERROR JSON on 200 and 500 remains visible with reference and is not stored',async()=>{
  for(const status of [200,500]) { const h=harness(()=>jsonResponse(makeEnvelope(),status)); await h.chat.ask('q');
    assert.match(allText(h.chat.shadowRoot.refs['.output']),/Reference: 0123456789abcdef/); assert.equal(JSON.parse(h.storage.get('ai-chat-history') || '[]').length,0); }
}));
tests.push(check('older stored ERROR history is excluded while valid prior turn remains',async()=>{
  const h=harness(()=>jsonResponse(makeEnvelope('ANSWER','grounded reply')),[
    ['valid q','valid answer',[],{outcome:'ANSWER'}],['old error','Reference: 0123456789abcdef',[],{outcome:'ERROR'}],
  ]);
  await h.chat.ask('next q'); const sent=JSON.parse(h.requests[0].history); assert.deepEqual(sent,[['valid q','valid answer']]);
}));
for(const [outcome,answer] of [['ANSWER','grounded reply'],['NO_INFORMATION','no information'],['CLARIFY','Which one?']]) tests.push(check(`valid ${outcome} response remains functional`,async()=>{
  const data=makeEnvelope(outcome,`<p>${answer}</p>`); if(outcome==='CLARIFY') data.meta.options=['one','two'];
  const h=harness(()=>jsonResponse(data)); await h.chat.ask('q'); const out=h.chat.shadowRoot.refs['.output'];
  assert.ok(out.children.some(x=>x.classList.contains('ai'))); assert.equal(JSON.parse(h.storage.get('ai-chat-history')).length,1);
  if(outcome==='CLARIFY') assert.equal(out.querySelectorAll('.options')[0].children.length,2);
}));
tests.push(check('real ANSWER source rows keep their link and citation fields',async()=>{
  const data=makeEnvelope('ANSWER','<p>grounded reply</p>');
  data.sources=[{page:'kitchen:coffee',url:'/doku.php?id=kitchen:coffee',title:'Coffee machine',score:'95.00%'}];
  const h=harness(()=>jsonResponse(data)); await h.chat.ask('q');
  const link=h.chat.shadowRoot.refs['.output'].querySelector('a');
  assert.ok(link); assert.equal(link.href,'/doku.php?id=kitchen:coffee');
  assert.equal(link.textContent,'Coffee machine'); assert.equal(link.title,'kitchen:coffee (95.00%)');
}));

(async()=>{
  let passed=0; for(const t of tests) { try { await t.fn(); console.log(`PASS ${t.name}`); passed++; } catch(e) { console.error(`FAIL ${t.name}: ${e.message}`); } }
  console.log(`SUMMARY ${passed}/${tests.length} passed`); if(passed!==tests.length) process.exitCode=1;
})().catch(e=>{console.error(e);process.exitCode=1;});

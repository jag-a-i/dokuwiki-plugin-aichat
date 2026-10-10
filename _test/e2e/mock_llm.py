"""Synthetic OpenAI-compatible endpoint for browser E2E tests. No real model.
/v1/embeddings: hashed bag-of-words vectors. /v1/chat/completions: deterministic policy
that emits the plugin's decision format, plus deliberate junk (duplicate footer, fake source)
so the server-side formatter is exercised. Records every request to requests.jsonl."""
import hashlib, json, math, re, sys
from http.server import BaseHTTPRequestHandler, HTTPServer

DIM = 64
LOG = sys.argv[2] if len(sys.argv) > 2 else '/tmp/mock_requests.jsonl'
STOP = set('the a an to and or of your you is on in for my how do i it what which with open'.split())

def embed(text):
    v = [0.0] * DIM
    for w in re.findall(r'[a-z0-9-]+', text.lower()):
        if w in STOP or len(w) < 3: continue
        w = {'email': 'e-mail', 'mail': 'e-mail'}.get(w, w)
        v[int(hashlib.md5(w.encode()).hexdigest(), 16) % DIM] += 1
    n = math.sqrt(sum(x * x for x in v)) or 1
    return [x / n for x in v]

def chat(messages):
    prompt = messages[-1]['content']
    if 'DECISION:' not in prompt:  # rephrase prompt
        m = re.search(r'User Follow-up question: (.*)', prompt)
        return m.group(1).strip() if m else 'unknown'
    labels = re.findall(r'^\[(S\d+)\] (.+)$', prompt, re.M)
    q = re.search(r'User Question: (.*)', prompt).group(1)
    allowed = 'Clarification is allowed' in prompt
    pw = [(s, t) for s, t in labels if 'password' in t.lower()]
    specific = any(k in q.lower() for k in ('vpn', 'e-mail', 'email', 'crm'))
    if allowed and len(pw) >= 2 and not specific:
        opts = '\n'.join(f'OPTION: {t.replace(" password", "")} | {s}' for s, t in pw)
        return f'DECISION: CLARIFY\nQUESTION: Which account do you want to change the password for?\n{opts}'
    if not pw:
        return 'DECISION: NO_INFORMATION'
    s, t = pw[0]
    return (f'DECISION: ANSWER\nUSED: {s}\nANSWER:\nSynthetic answer about {t} [{s}].\n\n'
            'Please see the following articles for more information:\n\nSources:\n- [Secret](/doku.php?id=it:hr:password)')

class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers['Content-Length'])) or b'{}')
        entry = {'path': self.path, 'body': body}
        if self.path.endswith('/api/public/otel/v1/traces'):  # local stand-in: records request shape only, not Langfuse behaviour
            entry['headers'] = {k.lower(): v for k, v in self.headers.items()}
        with open(LOG, 'a') as f: f.write(json.dumps(entry) + '\n')
        if 'headers' in entry:
            self.send_response(200); self.send_header('Content-Length', '2'); self.end_headers(); self.wfile.write(b'{}'); return
        if self.path.endswith('/embeddings'):
            out = {'data': [{'embedding': embed(t)} for t in body['input']], 'usage': {'total_tokens': 1}}
        elif self.path.endswith('/chat/completions'):
            out = {'choices': [{'message': {'content': chat(body['messages'])}}],
                   'usage': {'prompt_tokens': 1, 'completion_tokens': 1}}
        else:
            self.send_response(404); self.end_headers(); return
        data = json.dumps(out).encode()
        self.send_response(200); self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(data))); self.end_headers(); self.wfile.write(data)

HTTPServer(('127.0.0.1', int(sys.argv[1])), H).serve_forever()

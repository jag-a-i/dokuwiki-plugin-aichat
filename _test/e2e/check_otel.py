"""Verify the trace export the E2E run delivered to a local stand-in endpoint (real HTTP, no external network).
This checks the REQUEST SHAPE only (path, headers, JSON body, spans, no leaks). It is not a Langfuse instance and
says nothing about which Langfuse versions accept or map these requests."""
import base64, json, sys
reqs = [json.loads(l) for l in open(sys.argv[1]) if 'otel' in l]
reqs = [r for r in reqs if r['path'].endswith('/api/public/otel/v1/traces')]
fails = []
def check(name, cond):
    print(('PASS ' if cond else 'FAIL ') + name)
    if not cond: fails.append(name)
check('traces delivered over real HTTP', len(reqs) >= 3)
h = reqs[0]['headers'] if reqs else {}
check('Basic auth with placeholder keys', h.get('authorization') == 'Basic ' + base64.b64encode(b'pk-lf-placeholder:sk-lf-placeholder').decode())
check('ingestion version header', h.get('x-langfuse-ingestion-version') == '4')
check('JSON content type', h.get('content-type', '').startswith('application/json'))
names, outcomes = set(), set()
for r in reqs:
    for s in r['body']['resourceSpans'][0]['scopeSpans'][0]['spans']:
        names.add(s['name'])
        for a in s['attributes']:
            if a['key'] == 'langfuse.trace.metadata.outcome': outcomes.add(a['value']['stringValue'])
check('clarification, follow-up, ACL re-check, model and render spans present',
      {'aichat.clarify_decision', 'aichat.followup_resolution', 'aichat.acl_recheck', 'aichat.model_call', 'aichat.render'} <= names)
check('outcomes CLARIFY, ANSWER and NO_INFORMATION traced', {'CLARIFY', 'ANSWER', 'NO_INFORMATION'} <= outcomes)
blob = json.dumps([r['body'] for r in reqs])
for leak in ['vpnctl', 'change my password', 'alice', 'synthetic-alice-pw', 'sk-lf-placeholder', 'HIDDEN-HR-MARKER', '127.0.0.1']:
    check(f'no "{leak}" in exported payloads', leak not in blob)
print(f'\nOTEL SUMMARY: {len(reqs)} traces, {len(fails)} failed checks')
sys.exit(1 if fails else 0)

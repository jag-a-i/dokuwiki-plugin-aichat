"""Local test HTTP(S) server for the telemetry redirect regression. Never contacts other hosts.
usage: http_test_server.py PORT LOGFILE STATUS [LOCATION] [CERTFILE KEYFILE]
Every request is logged as one JSON line (method, path, headers, body length, body); response = STATUS (+ Location)."""
import json, os, ssl, sys
from http.server import BaseHTTPRequestHandler, HTTPServer
port, log, status = int(sys.argv[1]), sys.argv[2], int(sys.argv[3])
location = sys.argv[4] if len(sys.argv) > 4 and sys.argv[4] != '-' else None
class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def handle_any(self):
        n = int(self.headers.get('Content-Length') or 0)
        body = self.rfile.read(n).decode('utf-8', 'replace') if n else ''
        with open(log, 'a') as f:
            f.write(json.dumps({'method': self.command, 'path': self.path,
                                'headers': {k.lower(): v for k, v in self.headers.items()}, 'len': n, 'body': body}) + '\n')
        out = os.environ.get('RESPONSE_BODY', '').encode()  # optional response body (e.g. OTLP partialSuccess)
        self.send_response(status)
        if location: self.send_header('Location', location)
        if out: self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(out))); self.end_headers()
        if out: self.wfile.write(out)
    do_GET = do_POST = do_PUT = handle_any
srv = HTTPServer(('127.0.0.1', port), H)
if len(sys.argv) > 6:
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.load_cert_chain(sys.argv[5], sys.argv[6])
    srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
print('ready', flush=True)
srv.serve_forever()

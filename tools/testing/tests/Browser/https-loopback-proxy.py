"""Disposable TLS bridge for browser acceptance; forwards only to local PHP."""
import http.client
import http.server
import ssl
import sys

CERT, KEY, PORT, UPSTREAM = sys.argv[1], sys.argv[2], int(sys.argv[3]), int(sys.argv[4])

class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self): self.forward()
    def do_POST(self): self.forward()
    def do_HEAD(self): self.forward()
    def log_message(self, *_): pass
    def forward(self):
        length = int(self.headers.get('Content-Length', '0'))
        body = self.rfile.read(length) if length else None
        headers = {k: v for k, v in self.headers.items() if k.lower() not in ('host', 'connection') and not k.lower().startswith('x-forwarded-')}
        headers['Host'] = self.headers.get('Host', 'psikotes.oncam.id')
        headers['X-Forwarded-Host'] = headers['Host']
        headers['X-Forwarded-Proto'] = 'https'
        headers['X-Forwarded-Port'] = '443'
        headers['X-Forwarded-For'] = '127.0.0.1'
        conn = http.client.HTTPConnection('127.0.0.1', UPSTREAM, timeout=10)
        conn.request(self.command, self.path, body=body, headers=headers)
        response = conn.getresponse()
        data = response.read()
        self.send_response(response.status)
        for key, value in response.getheaders():
            if key.lower() not in ('connection', 'transfer-encoding'):
                self.send_header(key, value)
        self.end_headers()
        self.wfile.write(data)

server = http.server.ThreadingHTTPServer(('127.0.0.1', PORT), Handler)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(CERT, KEY)
server.socket = context.wrap_socket(server.socket, server_side=True)
server.serve_forever()

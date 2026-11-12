"""
TLS proxy for the integration tests: accepts HTTPS connections and forwards them to a local HTTP server.

The plugin uses HTTPS for WebDAV servers, but the built-in web server of PHP only supports HTTP.

Usage: python3 tls-proxy.py <listen port> <target port> <certificate file> <key file>

@package external-files-from-webdav
"""

import select
import socket
import ssl
import sys
import threading


def handle(connection, context, target_port):
    """Forward the data between the TLS client and a new connection to the HTTP server, until one is closed."""
    try:
        client = context.wrap_socket(connection, server_side=True)
    except (OSError, ssl.SSLError):
        connection.close()
        return

    try:
        server = socket.create_connection(("127.0.0.1", target_port))
    except OSError:
        client.close()
        return

    try:
        while True:
            # use the data, which is already decrypted, before waiting for new data.
            readable = [client] if client.pending() else select.select([client, server], [], [], 300)[0]
            if not readable:
                break
            for source in readable:
                data = source.recv(65536)
                if not data:
                    return
                (server if source is client else client).sendall(data)
    except (OSError, ssl.SSLError):
        pass
    finally:
        client.close()
        server.close()


def main():
    listen_port, target_port, certificate, key = int(sys.argv[1]), int(sys.argv[2]), sys.argv[3], sys.argv[4]

    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(certificate, key)

    listener = socket.create_server(("127.0.0.1", listen_port))
    while True:
        connection, _ = listener.accept()
        threading.Thread(target=handle, args=(connection, context, target_port), daemon=True).start()


if __name__ == "__main__":
    main()

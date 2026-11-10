# FastCGI pass-through to the Laravel front controller.
#
# Included by every location that serves the API. The one subtlety:
# SCRIPT_FILENAME is a FIXED path that exists inside the PHP-FPM container, and
# there is deliberately no try_files here. The gateway holds no copy of the
# application code, and it does not need one - this API serves no static files,
# so every request is the front controller. Trying to stat the file locally
# would fail; handing PHP a client-controlled path would be a remote code
# execution bug.

fastcgi_pass scp_api;
# Required for the upstream keepalive pool to actually be used.
fastcgi_keep_conn on;

fastcgi_param SCRIPT_FILENAME  /var/www/html/public/index.php;
fastcgi_param SCRIPT_NAME      /index.php;
fastcgi_param DOCUMENT_ROOT    /var/www/html/public;

fastcgi_param QUERY_STRING     $query_string;
fastcgi_param REQUEST_METHOD   $request_method;
fastcgi_param CONTENT_TYPE     $content_type;
fastcgi_param CONTENT_LENGTH   $content_length;
fastcgi_param REQUEST_URI      $request_uri;
fastcgi_param DOCUMENT_URI     $document_uri;
fastcgi_param SERVER_PROTOCOL  $server_protocol;
fastcgi_param REQUEST_SCHEME   $scheme;
fastcgi_param HTTPS            $https if_not_empty;

fastcgi_param GATEWAY_INTERFACE CGI/1.1;
fastcgi_param SERVER_SOFTWARE   nginx;
fastcgi_param REMOTE_ADDR       $remote_addr;
fastcgi_param REMOTE_PORT       $remote_port;
fastcgi_param SERVER_ADDR       $server_addr;
fastcgi_param SERVER_PORT       $server_port;
fastcgi_param SERVER_NAME       $host;

# Laravel reads the correlation id from this header; it is minted here when the
# client does not send one, so a request is traceable end to end.
fastcgi_param HTTP_X_REQUEST_ID $request_id_final;

fastcgi_index index.php;
fastcgi_hide_header X-Powered-By;

# Bound every stage, so one slow dependency cannot hold connections open.
fastcgi_connect_timeout 5s;
fastcgi_send_timeout    30s;
fastcgi_read_timeout    30s;

fastcgi_buffering    on;
fastcgi_buffer_size  16k;
fastcgi_buffers      16 16k;

# Never retry a non-idempotent write just because the upstream was slow:
# a retried POST /orders could create a second order.
fastcgi_next_upstream error timeout invalid_header;
fastcgi_next_upstream_tries 1;

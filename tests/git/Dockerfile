# Test-only Git HTTPS server for the Git deployer's integration tests. See README.md.
FROM alpine:3.20
RUN apk add --no-cache git git-daemon nginx fcgiwrap spawn-fcgi apache2-utils openssl \
	&& mkdir -p /srv/git /etc/git-test /run/nginx \
	&& openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=git.test" \
		-addext "subjectAltName=DNS:git.test" -keyout /etc/git-test/key.pem -out /etc/git-test/cert.pem 2>/dev/null \
	&& htpasswd -bc /etc/git-test/htpasswd deploy git-test-token \
	&& git config --system user.name "Someone Else" && git config --system user.email "someone@example.test" \
	&& git config --system init.defaultBranch main \
	&& git config --system core.hooksPath /srv/git-hooks \
	&& chown -R nginx:nginx /srv/git
COPY nginx.conf /etc/nginx/http.d/default.conf
COPY inspect.sh /srv/inspect.sh
COPY hooks /srv/git-hooks
RUN chmod 755 /srv/inspect.sh /srv/git-hooks/*
EXPOSE 443
CMD ["sh", "-c", "spawn-fcgi -s /run/fcgiwrap.sock -u nginx -g nginx -U nginx -G nginx -- /usr/bin/fcgiwrap && nginx -g 'daemon off;'"]

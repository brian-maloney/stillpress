# Test-only SFTP server for the SFTP deployer's integration tests. See README.md.
FROM alpine:3.20
RUN apk add --no-cache openssh-server openssh-sftp-server \
	&& ssh-keygen -A \
	&& adduser -D -s /bin/sh deploy \
	&& echo 'deploy:deploy-test-password' | chpasswd \
	&& mkdir -p /home/deploy/.ssh /home/deploy/www /home/deploy/readonly \
	&& chown deploy:deploy /home/deploy/www \
	&& chmod 555 /home/deploy/readonly
COPY .keys/authorized_keys /home/deploy/.ssh/authorized_keys
COPY sshd_config /etc/ssh/sshd_config
RUN chown -R deploy:deploy /home/deploy/.ssh && chmod 700 /home/deploy/.ssh && chmod 600 /home/deploy/.ssh/authorized_keys
EXPOSE 22
CMD ["/usr/sbin/sshd", "-D", "-e"]

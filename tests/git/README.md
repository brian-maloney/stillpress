# Test Git server

A throwaway HTTPS Git server for the Git deployer's integration tests: nginx in front of Git's own
`git-http-backend`. Everything here is **test-only** and must never be used anywhere else.

```bash
npm run git:start   # builds the image and joins it to the wp-env network as git.test
npm run git:stop
```

- Push over `https://git.test/repos/<name>.git` as user `deploy`, token `git-test-token`.
- The certificate is self-signed for `git.test`; `npm run git:start` copies it to `tests/git/.keys/cert.pem`
  (git-ignored), and the tests pass it to the transport as the CA bundle. TLS is still verified.
- `https://git.test/inspect?action=create|seed|refs|tree|show|log|fsck&repo=…&ref=…&path=…` is an unauthenticated
  helper the tests use to create repositories (`seed` adds a `gh-pages` commit made elsewhere) and read what was
  pushed.

Tests that need it skip themselves when `git.test:443` isn't reachable.

# Workflows (GitHub Actions)

Estes YAMLs vivem em `ci/` porque o token usado no primeiro push não tinha o
escopo `workflow`. Para ativá-los:

```bash
gh auth refresh -s workflow        # concede o escopo (uma vez)
git mv ci/build-llama.yml .github/workflows/build-llama.yml
git mv ci/lint.yml        .github/workflows/lint.yml
git commit -m "ci: ativa workflows" && git push
```

- **build-llama.yml** — dispara em tag `v*` (ex.: `git tag v1.0 && git push origin v1.0`) e compila o `llama-cli`, publicando os pacotes no Releases.
- **lint.yml** — `php -l` (7.4 e 8.2) + `bash -n` a cada push/PR.

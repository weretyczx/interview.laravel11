#!/bin/bash
set -e

# ===================================================================
# 本地 GitLab CI 模擬執行 (gitlab-ci-local)
# https://github.com/firecow/gitlab-ci-local
#
# 本地執行機密設定請放到 .env.ci
#   CI_REGISTRY_USER=xxxxxx@gmail.com
#   CI_GITLAB_TOKEN=glpat-xxxxxxxxxxxx
#   SSH_PRIVATE_KEY_PATH=$HOME/.ssh/id_rsa
# ===================================================================

# 載入機密
if [ -f .env.ci ]; then
    set -a
    . ./.env.ci
    set +a
else
    echo "[ERROR] .env.ci 不存在，請先建立並填入 CI_REGISTRY_USER / CI_GITLAB_TOKEN"
    exit 1
fi

# # 修掉 "Unable to retrieve default remote branch" 警告
# git remote set-head origin main 2>/dev/null || true

# 讓 host docker 也登入 GitLab registry，否則 gitlab-ci-local
# 用 host docker pull 私有 image (e.g. test:phpunit 的 image: $TEST_IMAGE) 會 401
echo "$CI_GITLAB_TOKEN" | docker login -u "$CI_REGISTRY_USER" --password-stdin registry.gitlab.com

# Apple Silicon 預先以 amd64 拉 mysql:8.0.46-debian (官方無 arm64 build)
# 配合下方 --pull-policy=if-not-present，gitlab-ci-local 不會再嘗試重 pull
if [ "$(uname -m)" = "arm64" ]; then
    docker pull --platform linux/amd64 mysql:8.0.46-debian
fi

# 本地讀 SSH key 進變數 (給 deploy job 用)
if [ -n "${SSH_PRIVATE_KEY_PATH:-}" ] && [ -f "$SSH_PRIVATE_KEY_PATH" ]; then
    export SSH_PRIVATE_KEY="$(cat "$SSH_PRIVATE_KEY_PATH")"
fi

gitlab-ci-local \
    --cleanup \
    --no-artifacts-to-source \
    --pull-policy=if-not-present \
    --container-executable=docker \
    --privileged true \
    --variable "CI_REGISTRY=registry.gitlab.com" \
    --variable "CI_REGISTRY_USER=$CI_REGISTRY_USER" \
    --variable "CI_GITLAB_TOKEN=$CI_GITLAB_TOKEN" \
    --variable "SSH_PRIVATE_KEY=${SSH_PRIVATE_KEY:-}" \
    --concurrency=1 \
    "$@"

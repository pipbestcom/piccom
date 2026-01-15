#!/bin/bash

echo "🚀 部署中电数媒公共图片托管平台到GitHub Pages"
echo "================================================"

# 检查是否在正确的目录
if [ ! -f "generate-directory-data.js" ]; then
    echo "❌ 错误：请在项目根目录下运行此脚本"
    exit 1
fi

# 生成目录数据
echo "📊 生成目录数据..."
node generate-directory-data.js

if [ $? -ne 0 ]; then
    echo "❌ 生成目录数据失败"
    exit 1
fi

# 检查是否有未提交的更改
if [ -n "$(git status --porcelain)" ]; then
    echo "📝 发现未提交的更改，正在提交..."

    # 添加所有更改
    git add .

    # 提交更改
    git commit -m "更新图片文件和目录数据 $(date +%Y-%m-%d)"

    if [ $? -ne 0 ]; then
        echo "❌ 提交失败"
        exit 1
    fi

    echo "✅ 更改已提交"
else
    echo "ℹ️ 没有发现新的更改"
fi

# 推送到GitHub
echo "📤 推送到GitHub..."
git push origin main

if [ $? -ne 0 ]; then
    echo "❌ 推送失败"
    exit 1
fi

echo "✅ 部署完成！"
echo "🌐 访问您的网站：https://$(git config --get remote.origin.url | sed 's/.*github.com[:/]\([^.]*\).*/\1/').github.io/$(basename $(git rev-parse --show-toplevel))/"
echo ""
echo "💡 提示：如果这是第一次部署，GitHub Pages可能需要几分钟才能生效"
const express = require('express');
const path = require('path');
const cors = require('cors');

const app = express();
const PORT = process.env.PORT || 3000;

// 中间件
app.use(cors());
app.use(express.static(path.join(__dirname, 'public')));

// API 路由 - 获取目录结构
app.get('/api/directory', (req, res) => {
  try {
    const directoryData = require('./public/directory-data.js');
    res.json(directoryData);
  } catch (error) {
    res.status(500).json({ error: 'Failed to load directory data' });
  }
});

// 根路由
app.get('/', (req, res) => {
  res.sendFile(path.join(__dirname, 'public', 'index.html'));
});

// 启动服务器
app.listen(PORT, () => {
  console.log(`Server running at http://localhost:${PORT}`);
  console.log('Make sure to run "npm run scan" first to generate directory data');
});
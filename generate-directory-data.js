const fs = require('fs');
const path = require('path');

function scanDirectory(dirPath, basePath = '') {
  const result = {};
  const items = fs.readdirSync(dirPath);

  // 排除的目录和文件
  const excludeItems = [
    '.git',
    'node_modules',
    '.idea',
    '.DS_Store',
    'generate-directory-data.js',
    'scan-directory.js',
    'server.js',
    'deploy.sh',
    'package.json',
    'README.md',
    '.gitignore'
  ];

  items.forEach(item => {
    // 跳过需要排除的项目
    if (excludeItems.includes(item)) return;

    const fullPath = path.join(dirPath, item);
    const relativePath = path.join(basePath, item);
    const stats = fs.statSync(fullPath);

    if (stats.isDirectory()) {
      result[item + '/'] = {
        type: 'folder',
        children: scanDirectory(fullPath, relativePath + '/')
      };
    } else {
      // 获取文件扩展名
      const ext = path.extname(item).toLowerCase();
      const isImage = ['.jpg', '.jpeg', '.png', '.gif', '.webp', '.svg', '.bmp'].includes(ext);

      if (isImage) {
        // 模拟图片尺寸和大小（实际项目中可以根据文件名或其他方式获取真实数据）
        const mockSizes = {
          '.jpg': ['2.3MB', '4.1MB', '3.2MB', '2.8MB'],
          '.png': ['1.8MB', '1.5MB', '120KB'],
          '.webp': ['950KB'],
          '.svg': ['45KB'],
          '.gif': ['1.2MB']
        };

        const mockDimensions = {
          '.jpg': ['1920x1080', '3840x2160', '2560x1440', '1920x1080'],
          '.png': ['1280x720', '1920x1080', '200x200'],
          '.webp': ['800x600'],
          '.svg': ['SVG'],
          '.gif': ['800x600']
        };

        const size = mockSizes[ext] ? mockSizes[ext][Math.floor(Math.random() * mockSizes[ext].length)] : '1MB';
        const dimensions = mockDimensions[ext] ? mockDimensions[ext][Math.floor(Math.random() * mockDimensions[ext].length)] : '未知';

        result[item] = {
          type: 'image',
          size: size,
          dimensions: dimensions,
          path: relativePath,
          url: relativePath // 相对于public目录的URL
        };
      } else {
        // 其他文件类型
        const sizeKB = Math.floor(stats.size / 1024);
        result[item] = {
          type: 'file',
          size: sizeKB > 1024 ? `${(sizeKB / 1024).toFixed(1)}MB` : `${sizeKB}KB`,
          path: relativePath,
          url: relativePath
        };
      }
    }
  });

  return result;
}

// 扫描根目录（排除.git, node_modules等）
const rootDir = __dirname;
const directoryData = scanDirectory(rootDir);

// 生成 directory-data.json 文件
const outputPath = path.join(__dirname, 'directory-data.json');
fs.writeFileSync(outputPath, JSON.stringify(directoryData, null, 2), 'utf8');

console.log('Directory scan completed. Data saved to directory-data.json');
console.log('Total items found:', Object.keys(directoryData).length);

// 显示目录结构预览
console.log('\nDirectory structure:');
function printStructure(obj, prefix = '') {
  Object.keys(obj).forEach(key => {
    const item = obj[key];
    console.log(prefix + key + (item.type === 'folder' ? '/' : ''));
    if (item.type === 'folder' && item.children) {
      printStructure(item.children, prefix + '  ');
    }
  });
}
printStructure(directoryData);
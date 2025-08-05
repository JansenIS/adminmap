const http = require('http');
const fs = require('fs');
const path = require('path');
const url = require('url');

const dataFile = path.join(__dirname, 'provinces.json');
let provinces = [];
try {
  provinces = JSON.parse(fs.readFileSync(dataFile, 'utf8'));
} catch (e) {
  provinces = [];
}

function sendJSON(res, status, data) {
  res.writeHead(status, {
    'Content-Type': 'application/json',
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Methods': 'GET,PUT,OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type'
  });
  res.end(JSON.stringify(data));
}

function handleAPI(req, res) {
  const parsed = url.parse(req.url, true);
  const parts = parsed.pathname.split('/').filter(Boolean);

  if (parts[0] !== 'api') return false;

  if (req.method === 'OPTIONS') {
    res.writeHead(204, {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'GET,PUT,OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type'
    });
    res.end();
    return true;
  }

  if (parts[1] === 'provinces') {
    if (req.method === 'GET' && parts.length === 2) {
      sendJSON(res, 200, provinces);
      return true;
    }

    if (parts.length === 3) {
      const id = parseInt(parts[2], 10);
      const idx = provinces.findIndex(p => p.id === id);
      if (idx === -1) {
        sendJSON(res, 404, { error: 'Not found' });
        return true;
      }
      if (req.method === 'GET') {
        sendJSON(res, 200, provinces[idx]);
        return true;
      }
      if (req.method === 'PUT') {
        let body = '';
        req.on('data', chunk => body += chunk);
        req.on('end', () => {
          try {
            const update = JSON.parse(body || '{}');
            provinces[idx] = { ...provinces[idx], ...update, id };
            fs.writeFileSync(dataFile, JSON.stringify(provinces, null, 2));
            sendJSON(res, 200, { success: true });
          } catch (err) {
            sendJSON(res, 400, { error: 'Invalid JSON' });
          }
        });
        return true;
      }
    }
  }

  sendJSON(res, 404, { error: 'Not found' });
  return true;
}

const server = http.createServer((req, res) => {
  if (handleAPI(req, res)) return;

  const reqPath = url.parse(req.url).pathname;
  const filePath = path.join(__dirname, reqPath === '/' ? 'index.html' : reqPath);
  fs.readFile(filePath, (err, content) => {
    if (err) {
      res.writeHead(404, { 'Content-Type': 'text/plain' });
      res.end('Not found');
      return;
    }
    const ext = path.extname(filePath).toLowerCase();
    const type = {
      '.html': 'text/html',
      '.js': 'text/javascript',
      '.css': 'text/css',
      '.json': 'application/json',
      '.png': 'image/png',
      '.jpg': 'image/jpeg',
      '.jpeg': 'image/jpeg'
    }[ext] || 'text/plain';
    res.writeHead(200, { 'Content-Type': type });
    res.end(content);
  });
});

const port = process.env.PORT || 3000;
server.listen(port, () => {
  console.log(`Server running at http://localhost:${port}`);
});

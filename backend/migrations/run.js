/* Migration runner.
   Files named *_webshop_*.sql run against the webshop DB;
   files named *_general_db_*.sql run against the general DB
   (002 demo schema is dev-only; 003 outbox requires explicit approval
   on the production ERP — see ../README.md).
   Applied files are tracked in webshop.migrations_applied. */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import mysql from 'mysql2/promise';
import { config } from '../src/config.js';

const dir = path.dirname(fileURLToPath(import.meta.url));

async function main() {
  const ws = await mysql.createConnection({ ...config.webshopDb, multipleStatements: true });
  const gen = await mysql.createConnection({ ...config.generalDb, multipleStatements: true });

  await ws.query(`CREATE TABLE IF NOT EXISTS migrations_applied (
    file VARCHAR(120) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);

  const files = fs.readdirSync(dir).filter((f) => f.endsWith('.sql')).sort();
  for (const file of files) {
    const [done] = await ws.query('SELECT 1 FROM migrations_applied WHERE file = ?', [file]);
    if (done.length) { console.log(`skip  ${file} (already applied)`); continue; }

    let sql = fs.readFileSync(path.join(dir, file), 'utf8');
    const conn = file.includes('general_db') ? gen : ws;

    // mysql2 doesn't understand DELIMITER — split trigger files manually.
    if (sql.includes('DELIMITER')) {
      const blocks = sql
        .replace(/DELIMITER \$\$/g, '')
        .replace(/DELIMITER ;/g, '')
        .split('$$')
        .map((s) => s.trim())
        .filter(Boolean);
      for (const b of blocks) await conn.query(b);
    } else {
      await conn.query(sql);
    }

    await ws.query('INSERT INTO migrations_applied (file) VALUES (?)', [file]);
    console.log(`apply ${file}`);
  }

  await ws.end(); await gen.end();
}

main().catch((e) => { console.error(e); process.exit(1); });

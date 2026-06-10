import mysql from 'mysql2/promise';
import { config } from './config.js';

/* Webshop dedicated DB — full read/write. */
export const webshopDb = mysql.createPool({
  ...config.webshopDb,
  waitForConnections: true,
  connectionLimit: 10,
  namedPlaceholders: true,
  charset: 'utf8mb4_unicode_ci',
  decimalNumbers: true,
});

/* General / ERP DB (Franchise Buddy) — the webshop NEVER writes to
   business tables here. The only writes permitted are by the sync
   worker marking outbox rows as processed. */
export const generalDb = mysql.createPool({
  ...config.generalDb,
  waitForConnections: true,
  connectionLimit: 4,
  namedPlaceholders: true,
  charset: 'utf8mb4_unicode_ci',
  decimalNumbers: true,
});

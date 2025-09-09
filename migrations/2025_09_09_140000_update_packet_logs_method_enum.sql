-- Migration: Update packet_logs method enum to include more HTTP methods
-- Created: 2025-09-09
-- Description: Expand the method enum to include additional HTTP methods

ALTER TABLE packet_logs MODIFY COLUMN method enum('GET','POST','PUT','DELETE','HEAD','OPTIONS','PATCH','CONNECT','TRACE','PROPFIND','PROPPATCH','MKCOL','COPY','MOVE','LOCK','UNLOCK','N/A','TLS_CLIENTHELLO') DEFAULT 'N/A';

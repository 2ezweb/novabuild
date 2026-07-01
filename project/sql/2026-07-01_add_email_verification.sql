-- Email verification on registration (see context.md for the full flow).
ALTER TABLE users
  ADD COLUMN email_verified_at DATETIME NULL AFTER status,
  ADD COLUMN verification_code CHAR(6) NULL AFTER email_verified_at,
  ADD COLUMN verification_code_expires_at DATETIME NULL AFTER verification_code;

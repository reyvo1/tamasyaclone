-- V140 Promotion code auto-apply event codes (expand-only)
ALTER TABLE promotion_codes
  ADD COLUMN IF NOT EXISTS auto_apply TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS event_name VARCHAR(190) DEFAULT NULL;

CREATE INDEX IF NOT EXISTS idx_promo_code_auto_apply
  ON promotion_codes (auto_apply, status, applies_to, created_at);

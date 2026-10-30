-- V141 Public reservation promo code (expand-only)
ALTER TABLE public_reservation_requests
  ADD COLUMN IF NOT EXISTS promo_code VARCHAR(80) DEFAULT NULL;

CREATE INDEX IF NOT EXISTS idx_public_reservation_promo_code
  ON public_reservation_requests (promo_code);

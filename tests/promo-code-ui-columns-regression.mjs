import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

test('public website reservation form exposes promo code column', () => {
  const src = readFileSync('webpublic/assets/public-core.js', 'utf8');
  assert.match(src, /Kode promo \(opsional\)/);
  assert.match(src, /promoCode:""/);
});

test('app booking creation form exposes promo code column', () => {
  const src = readFileSync('assets/chunks/rooms.js', 'utf8');
  assert.match(src, /Kode Promo \(opsional\)/);
  assert.match(src, /promoCode:__promo\|\|void 0/);
});

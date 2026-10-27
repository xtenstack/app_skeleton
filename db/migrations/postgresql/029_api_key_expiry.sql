-- Provisioning API (MAA-20261027-008, plan section 14): an API key may carry
-- an expiry. Used for a key issued with expires_in_days and for the old key
-- during a rotation, which stays valid for a short grace period so the
-- caller can store the new one before the old one dies. NULL = no expiry
-- (every existing key). ApiKeyAuth::resolve() refuses a key past expires_at.

ALTER TABLE api_keys ADD COLUMN expires_at TIMESTAMP;

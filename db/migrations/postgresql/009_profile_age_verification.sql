-- Admin-only age (18+, or the relevant jurisdiction's age of majority)
-- verification flag. Deliberately not self-service — only settable from the
-- admin-facing profile edit view (UsersController::profileAction), never
-- from the user's own self-service Account page.
ALTER TABLE user_profiles ADD COLUMN age_verified_at TIMESTAMP;

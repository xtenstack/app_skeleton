-- SQLite port of postgresql/027_user_roles.sql; keep the two in step.
-- A user may hold more than one role (Travis, 23 Oct 2026, after the LMS
-- module's `instructor` role could not be given to an `operator` account).
-- user_roles holds the set; users.role_id stays as the PRIMARY role, both
-- for code that has not moved to the set yet (every module's copied gate
-- reads it) and as the row's "main hat" in the Users list. The effective
-- set is users.role_id UNION user_roles (Users::roleIds()), so a path that
-- still writes only role_id (signup, create-agent, module fixtures) keeps
-- working. Seeded from the one role every user already has.
CREATE TABLE user_roles (
    user_id     INTEGER NOT NULL,
    role_id     INTEGER NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (role_id) REFERENCES roles(id)
);

CREATE INDEX idx_user_roles_role_id ON user_roles (role_id);

INSERT INTO user_roles (user_id, role_id) SELECT id, role_id FROM users;

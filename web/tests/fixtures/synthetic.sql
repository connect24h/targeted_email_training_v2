-- 架空ドメインだけを使う最小fixture。本番data・個人情報へ依存しない。
INSERT INTO tenants (id, name, slug, data_dir) VALUES
  (1, 'Example Tenant', 'example-tenant', '/tmp/tet2-example-tenant'),
  (2, 'Other Tenant', 'other-tenant', '/tmp/tet2-other-tenant');

INSERT INTO users (id, tenant_id, email, password_hash, name, role, status) VALUES
  (1, 1, 'operator@example.test', 'fixture-not-a-real-hash', 'Fixture Operator', 'operator', 'active'),
  (2, 2, 'operator@other.example.test', 'fixture-not-a-real-hash', 'Other Operator', 'operator', 'active');

INSERT INTO targets (id, tenant_id, tenant_no, email, name, company, status) VALUES
  (1, 1, 1, 'target1@example.test', 'Target One', 'Example Co', 'active'),
  (2, 1, 2, 'target2@example.test', 'Target Two', 'Example Co', 'active'),
  (3, 2, 1, 'target@other.example.test', 'Other Target', 'Other Co', 'active');

INSERT INTO groups (id, tenant_id, name, kind) VALUES
  (1, 1, 'Example Group', 'custom'),
  (2, 2, 'Other Group', 'custom');
INSERT INTO target_group (target_id, group_id) VALUES (1, 1), (3, 2);

INSERT INTO templates (id, tenant_id, kind, name, content, is_preset) VALUES
  (1, NULL, 'subject', 'Shared Subject', 'Fixture subject', 1),
  (2, 1, 'body', 'Tenant Body', 'Fixture body', 0);

INSERT INTO campaigns (id, tenant_id, name, status, created_by) VALUES
  (2, 1, 'Fixture Campaign', 'draft', 1),
  (3, 2, 'Other Campaign', 'draft', 2);

INSERT INTO campaign_targets
  (id, campaign_id, target_id, tracking_id, content_no, send_status)
VALUES
  (1, 2, 1, '0000000001', 1, 'pending'),
  (2, 3, 3, '0000000002', 1, 'pending');

INSERT INTO events
  (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source)
VALUES
  (1, 2, '0000000001', 'open', '2026-01-01 09:00:00', 'fixture');

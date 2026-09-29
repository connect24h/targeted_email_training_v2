<?php
declare(strict_types=1);

/**
 * 部署の階層(C3、G14)のテストと E2E で共有する合成データ。Db が合成 DB を向いていることが前提。
 *
 * 部署(組織1、実対象者):
 *   A、B 「営業本部/東日本営業部/第1課」   C 「 営業本部 / 東日本営業部 / 第2課 」(前後と区切りの空白)
 *   D 「営業本部／西日本営業部」(全角の区切り)  H 「/営業本部//東日本営業部/」(空の段)
 *   E 「管理本部/総務部」  F 「管理本部」  G 部署なし
 * 数えないもの: テスト用 T(営業本部/東日本営業部/第1課)、削除済み X(管理本部/総務部)、組織2の Y(営業本部/他社)
 *
 * 教育: eラーニング(合格点80、期限 2026-09-30)と、アウェアネス(合格点なし)。
 *   点数 A100 B60 C90(期限後) D未受講 E80 F70 G50 H85、アウェアネス A40 E60。T0 X0 Y100
 *   分野: 設問 Q1=親「フィッシング」、Q2=親「パスワード」
 *   解答 A Q1○Q2○ / B Q1×Q2× / C Q1○ / H Q1×Q2○ / E Q1○Q2× / F Q2○ / G Q1× / T Q1× / Y Q1○
 * 訓練(キャンペーン「部署の階層」): 行動 A 表示+認証、B 表示、C 報告、D 表示、E 表示+報告、H 表示+認証、
 *   G は届かない宛先(対象数に入れない)、F は行動なし、T 表示+認証(テスト用)、X 表示(削除済み。訓練の表は今と同じく数える)
 *
 * @return array{elearning:int,awareness:int,campaign:int,targets:array<string,int>}
 */
function dept_levels_seed(): array
{
    $run = static fn(string $sql, array $p = []): int => Db::insert($sql, $p);
    $people = [
        'A' => [1, '営業本部/東日本営業部/第1課', 0, 'active'],
        'B' => [1, '営業本部/東日本営業部/第1課', 0, 'active'],
        'C' => [1, ' 営業本部 / 東日本営業部 / 第2課 ', 0, 'active'],
        'D' => [1, '営業本部／西日本営業部', 0, 'active'],
        'E' => [1, '管理本部/総務部', 0, 'active'],
        'F' => [1, '管理本部', 0, 'active'],
        'G' => [1, null, 0, 'active'],
        'H' => [1, '/営業本部//東日本営業部/', 0, 'active'],
        'T' => [1, '営業本部/東日本営業部/第1課', 1, 'active'],
        'X' => [1, '管理本部/総務部', 0, 'archived'],
        'Y' => [2, '営業本部/他社', 0, 'active'],
    ];
    $ids = [];
    $no = 300;
    foreach ($people as $key => [$tenant, $dept, $isTest, $status]) {
        $no++;
        $ids[$key] = $run('INSERT INTO targets (tenant_id, tenant_no, email, name, department, is_test, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$tenant, $no, 'dl-' . strtolower($key) . '@example.test', '受講者' . $key, $dept, $isTest, $status]);
    }

    $parentP = $run("INSERT INTO edu_tags (tenant_id, parent_id, name, sort_order) VALUES (1, NULL, 'フィッシング', 0)");
    $parentW = $run("INSERT INTO edu_tags (tenant_id, parent_id, name, sort_order) VALUES (1, NULL, 'パスワード', 1)");
    $cat = $run("INSERT INTO edu_categories (tenant_id, name, slug) VALUES (1, '部署の階層', 'dept-levels')");
    $cat2 = $run("INSERT INTO edu_categories (tenant_id, name, slug) VALUES (2, '部署の階層2', 'dept-levels-2')");
    $question = static function (int $tenant, int $category, int $tag) use ($run): int {
        $id = $run("INSERT INTO edu_questions (tenant_id, category_id, title, options, correct_answer) VALUES (?, ?, '設問', '[\"a\",\"b\"]', '[0]')",
            [$tenant, $category]);
        $run('INSERT INTO edu_question_tags (question_id, tag_id) VALUES (?, ?)', [$id, $tag]);
        return $id;
    };
    $q1 = $question(1, $cat, $parentP);
    $q2 = $question(1, $cat, $parentW);
    $q3 = $question(2, $cat2, $parentP);

    $el = $run("INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, pass_score, deadline, created_at) VALUES (1, 'eラーニング(部署の階層)', 'running', 'elearning', 80, '2026-09-30', '2026-09-01 09:00:00')");
    $aw = $run("INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, pass_score, created_at) VALUES (1, 'アウェアネス(部署の階層)', 'running', 'awareness_quiz', NULL, '2026-09-01 09:00:00')");
    $el2 = $run("INSERT INTO edu_deliveries (tenant_id, title, status, delivery_type, pass_score) VALUES (2, '組織2の配信', 'running', 'elearning', 80)");
    $seq = 0;
    $assign = static function (int $tenant, int $delivery, int $target, ?int $pct, ?string $at, array $answers = [])
        use ($run, &$seq): void {
        $seq++;
        $aid = $run('INSERT INTO edu_assignments (tenant_id, delivery_id, target_id, access_token, status, completed_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$tenant, $delivery, $target, str_pad((string) $seq, 32, 'd', STR_PAD_LEFT), $pct === null ? 'sent' : 'completed', $at]);
        if ($pct === null) {
            return;
        }
        $rid = $run('INSERT INTO edu_responses (tenant_id, assignment_id, percentage, completed_at) VALUES (?, ?, ?, ?)', [$tenant, $aid, $pct, $at]);
        foreach ($answers as $qid => $correct) {
            $run('INSERT INTO edu_response_answers (response_id, question_id, answer, is_correct, score_earned) VALUES (?, ?, ?, ?, ?)',
                [$rid, $qid, $correct ? '[0]' : '[1]', $correct ? 1 : 0, $correct ? 1 : 0]);
        }
    };
    $assign(1, $el, $ids['A'], 100, '2026-09-10 10:00:00', [$q1 => true, $q2 => true]);
    $assign(1, $el, $ids['B'], 60, '2026-09-11 10:00:00', [$q1 => false, $q2 => false]);
    $assign(1, $el, $ids['C'], 90, '2026-10-05 10:00:00', [$q1 => true]);
    $assign(1, $el, $ids['D'], null, null);
    $assign(1, $el, $ids['E'], 80, '2026-09-12 10:00:00', [$q1 => true, $q2 => false]);
    $assign(1, $el, $ids['F'], 70, '2026-09-13 10:00:00', [$q2 => true]);
    $assign(1, $el, $ids['G'], 50, '2026-09-14 10:00:00', [$q1 => false]);
    $assign(1, $el, $ids['H'], 85, '2026-09-15 10:00:00', [$q1 => false, $q2 => true]);
    $assign(1, $el, $ids['T'], 0, '2026-09-16 10:00:00', [$q1 => false]);
    $assign(1, $el, $ids['X'], 0, '2026-09-17 10:00:00', [$q1 => false]);
    $assign(1, $aw, $ids['A'], 40, '2026-09-18 10:00:00');
    $assign(1, $aw, $ids['E'], 60, '2026-09-19 10:00:00');
    $assign(2, $el2, $ids['Y'], 100, '2026-09-20 10:00:00', [$q3 => true]);

    $campaign = $run("INSERT INTO campaigns (tenant_id, name, status, created_by, created_at) VALUES (1, '部署の階層', 'done', 1, '2026-09-01 09:00:00')");
    $events = [
        'A' => ['click', 'auth'], 'B' => ['click'], 'C' => ['report'], 'D' => ['click'], 'E' => ['click', 'report'],
        'F' => [], 'G' => [], 'H' => ['click', 'auth'], 'T' => ['click', 'auth'], 'X' => ['click'],
    ];
    $i = 0;
    foreach ($events as $key => $types) {
        $tracking = sprintf('30000000%02d', ++$i);
        $run('INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, content_no, send_status, delivery_state) VALUES (?, ?, ?, 1, ?, ?)',
            [$campaign, $ids[$key], $tracking, 'sent', $key === 'G' ? 'undeliverable' : null]);
        foreach ($types as $j => $type) {
            $run("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source) VALUES (1, ?, ?, ?, ?, 'fixture')",
                [$campaign, $tracking, $type, sprintf('2026-09-01 1%d:%02d:00', $j, $i)]);
        }
    }
    return ['elearning' => $el, 'awareness' => $aw, 'campaign' => $campaign, 'targets' => $ids];
}

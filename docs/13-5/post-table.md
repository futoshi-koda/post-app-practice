＃＃posts（投稿）
| カラム名 | データ型 | 成約   | 説明   |
|:-------|:-----|:--------|:-------------|
| id | BIGINT | PRIMARY KEY、AUTO_INCREMENT | 投稿毎のユニーク番号 |
| user_id | BIGINT | NOT NULL、FOREGIN KEY | 投稿者毎のユニーク番号 |
| category_id | BIGINT | NOT NULL、FORGIN KEY | カテゴリー毎のユニーク番号 |
| title | VARCHAR(255) | NOT NULL | 投稿のタイトル |
| content | TEXT | NOT NULL | 投稿の本文 |
| create_at | TIMESTAMP | - | 作成日時 |
| update_at | TIMESTAMP | - | 更新日時 |
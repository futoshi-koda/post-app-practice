＃＃products（商品）
| カラム名 | データ型 | 成約   | 説明   |
|:-------|:-----|:--------|:-------------|
| id | BIGINT | PRIMARY KEY、AUTO_INCREMENT | 投稿毎のユニーク番号 |
| name | VARCHAR(50) | NOT NULL | 商品名 |
| discription | VARCHAR(100) | - | 商品説明 |
| price | INT | NOT NULL | 値段（円） |
| category | ENUM('drink','food') | NOT NULL | ドリンクまたはフード |
| is_available | BOOLEAN | NOT NULL | 販売可能時はTRUE、売り切れ時はFALSE |
| create_at | TIMESTAMP | - | 作成日時 |
| update_at | TIMESTAMP | - | 更新日時 |

＃＃orders（注文）
| カラム名 | データ型 | 成約   | 説明   |
|:-------|:-----|:--------|:-------------|
| id | BIGINT | PRIMARY KEY、AUTO_INCREMENT | 注文毎のユニーク番号 |
| order_number | VARCHAR(4) | NOT NULL | 注文番号 |
| total_amount | INT | NOT NULL | 合計金額(円) |
| price | INT | NOT NULL | 値段（円） |
| status | VARCHAR(20) | NOT NULL | 注文の状態。注文済、受け渡し済、取り消し済の想定 |
| order_at | DATETIME | NOT NULL | 注文日時 |
| create_at | TIMESTAMP | - | 作成日時 |
| update_at | TIMESTAMP | - | 更新日時 |

＃＃order_items（注文詳細）
| カラム名 | データ型 | 成約   | 説明   |
|:-------|:-----|:--------|:-------------|
| id | BIGINT | PRIMARY KEY、AUTO_INCREMENT | 注文明細毎のユニーク番号 |
| order_id | BUGINT | NOT NULL、FOREGIN KEY | どの注文番号の明細か |
| product_id | BUGINT | NOT NULL、FORGEIN KEY | どの商品か |
| quantity | INT | NOT NULL | 個数 |
| unit_price | INT | NOT NULL | 商品の単価 |
| create_at | TIMESTAMP | - | 作成日時 |
| update_at | TIMESTAMP | - | 更新日時 |
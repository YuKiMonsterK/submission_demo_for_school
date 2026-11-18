CREATE TABLE IF NOT EXISTS submissions (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    author text NOT NULL,
    category text NOT NULL,
    title text NOT NULL,
    body text NOT NULL,
    status text NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft', 'pending', 'approved'))
);

-- 讓已建立稿件表的展示資料庫也能直接更新。
ALTER TABLE submissions ADD COLUMN IF NOT EXISTS return_reason text;

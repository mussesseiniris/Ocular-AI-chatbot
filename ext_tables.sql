CREATE TABLE tx_chatbot_rate_limit (
    ip_hash VARCHAR(64) NOT NULL DEFAULT '',
    question_count INT NOT NULL DEFAULT 0,
    started_at INT NOT NULL DEFAULT 0,
    PRIMARY KEY (ip_hash)
);
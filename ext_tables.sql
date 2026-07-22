CREATE TABLE tx_chatbot_rate_limit (
    ip_hash VARCHAR(64) NOT NULL DEFAULT '',
    question_count INT NOT NULL DEFAULT 0,
    started_at INT NOT NULL DEFAULT 0,
    PRIMARY KEY (ip_hash)
);

CREATE TABLE tx_chatbot_interaction_log (
    uid INT NOT NULL AUTO_INCREMENT,
    crdate INT NOT NULL DEFAULT 0,
    ip_hash VARCHAR(64) NOT NULL DEFAULT '',
    session_id VARCHAR(64) NOT NULL DEFAULT '',
    turn INT NOT NULL DEFAULT 0,
    chunks_found INT NOT NULL DEFAULT 0,
    top_topic VARCHAR(64) NOT NULL DEFAULT '',
    status VARCHAR(32) NOT NULL DEFAULT '',
    PRIMARY KEY (uid),
    KEY ip_hash(ip_hash),
    KEY crdate(crdate),
    KEY session_id(session_id)
);
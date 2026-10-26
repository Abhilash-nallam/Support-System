CREATE TABLE IF NOT EXISTS support_tickets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ticket_code VARCHAR(32) NOT NULL UNIQUE,
 user_chat_id BIGINT NOT NULL,
 user_name VARCHAR(160) NULL,
 username VARCHAR(80) NULL,
 message TEXT NOT NULL,
 status ENUM('open','closed') NOT NULL DEFAULT 'open',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 replied_at TIMESTAMP NULL DEFAULT NULL,
 closed_at TIMESTAMP NULL DEFAULT NULL,
 INDEX idx_status_created(status,created_at),
 INDEX idx_user_created(user_chat_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS support_replies (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ticket_id BIGINT UNSIGNED NOT NULL,
 sender_type ENUM('admin','user') NOT NULL,
 message TEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_ticket_created(ticket_id,created_at),
 CONSTRAINT fk_support_replies_ticket FOREIGN KEY(ticket_id)
 REFERENCES support_tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

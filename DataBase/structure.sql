-- کاربران
CREATE TABLE
    users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(32) UNIQUE NOT NULL,
        phone VARCHAR(12) UNIQUE NOT NULL,
        email VARCHAR(254) UNIQUE,
        password TEXT NOT NULL,
        role ENUM ('user', 'leader', 'admin') DEFAULT 'user' NOT NULL,
        avatar TEXT,
        is_active BOOLEAN DEFAULT TRUE,
        registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL
    ) ENGINE = InnoDB;

--اطلاعات پروفایل
CREATE TABLE
    user_profiles (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        first_name VARCHAR(50),
        last_name VARCHAR(50),
        gender ENUM ('male', 'female'),
        birth_date DATE,
        province VARCHAR(50),
        city VARCHAR(50),
        registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE = InnoDB;

--اطلاعات گواهی نامه
CREATE TABLE
    user_certificates (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        first_name_en VARCHAR(50),
        last_name_en VARCHAR(50),
        father_name VARCHAR(50),
        national_id VARCHAR(20) UNIQUE,
        FOREIGN KEY (user_id) REFERENCES users (id)
    ) ENGINE = InnoDB;

--آدرس پستی
CREATE TABLE
    user_addresses (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        province VARCHAR(50),
        city VARCHAR(50),
        address TEXT,
        postal_code VARCHAR(10),
        receiver_phone VARCHAR(12),
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE = InnoDB;

-- جدول OTP برای ورود با شماره موبایل
CREATE TABLE
    otps (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        phone VARCHAR(12) NOT NULL,
        code VARCHAR(6) NOT NULL,
        expires_at INT UNSIGNED NOT NULL,
        is_used BOOLEAN DEFAULT FALSE NOT NULL,
        page VARCHAR(15) NOT NULL,
        user_ip VARCHAR(40) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL
    ) ENGINE = InnoDB;

-- دسته بندی ها
CREATE TABLE
    categories (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL
    ) ENGINE = InnoDB;

-- محصولات
CREATE TABLE
    products (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(255) UNIQUE NOT NULL,
        category_id INT UNSIGNED,
        type ENUM ('course', 'book') NOT NULL,
        thumbnail VARCHAR(255),
        title VARCHAR(255) NOT NULL,
        introduction TEXT,
        description TEXT,
        what_you_learn JSON,
        requirements JSON,
        level ENUM ('beginner', 'intermediate', 'advanced', 'expert'),
        price INT UNSIGNED NOT NULL,
        discount INT UNSIGNED DEFAULT 0,
        rating_avg DECIMAL(3, 2) DEFAULT 0.00,
        rating_count INT UNSIGNED DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

-- جزئیات دوره
CREATE TABLE
    course_details (
        product_id INT UNSIGNED PRIMARY KEY,
        type ENUM ('recorded', 'online') NOT NULL,
        lessons INT UNSIGNED,
        duration INT UNSIGNED,
        record_progress TINYINT DEFAULT 0,
        FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
    );

-- جزئیات جزوه
CREATE TABLE
    book_details (
        product_id INT UNSIGNED PRIMARY KEY,
        pages INT UNSIGNED,
        format ENUM ('PDF', 'PowerPoint', 'EPUB') NOT NULL,
        size INT UNSIGNED,
        chapters INT UNSIGNED,
        printed_version BOOLEAN DEFAULT FALSE,
        FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
    );

-- سرفصل ها
CREATE TABLE
    chapters (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id INT UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL,
        lessons_count INT UNSIGNED DEFAULT 0,
        duration INT UNSIGNED DEFAULT 0,
        FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
    );

-- درس های فصل
CREATE TABLE
    chapter_lessons (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        chapter_id INT UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL,
        duration INT UNSIGNED DEFAULT 0,
        free BOOLEAN DEFAULT FALSE,
        FOREIGN KEY (chapter_id) REFERENCES lesson_chapters (id) ON DELETE CASCADE
    );

-- مدرس ها
CREATE TABLE
    instructors (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        professional_title VARCHAR(50) NOT NULL,
        bio TEXT,
        categories_id JSON NOT NULL,
        registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users (id)
    ) ENGINE = InnoDB;

-- رزروها
CREATE TABLE
    reservations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        type ENUM ('course', 'book') NOT NULL,
        product_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        code VARCHAR(10) NOT NULL,
        discount_code_id INT,
        price BIGINT NOT NULL,
        printed BOOLEAN DEFAULT FALSE NOT NULL,
        status ENUM (
            'pending-pay',
            'need-approval',
            'sending',
            'finished',
            'canceled'
        ) DEFAULT 'pending-pay' NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
        FOREIGN KEY (product_id) REFERENCES products (id),
        FOREIGN KEY (user_id) REFERENCES users (id),
        FOREIGN KEY (discount_code_id) REFERENCES discount_codes (id)
    ) ENGINE = InnoDB;

-- پرداخت ها
CREATE TABLE
    transactions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        reservation_id INT UNSIGNED NOT NULL,
        amount BIGINT UNSIGNED NOT NULL,
        status ENUM ('pending', 'paid', 'failed', 'canceled') DEFAULT 'pending' NOT NULL,
        authority VARCHAR(36),
        card_hash VARCHAR(64),
        card_pan VARCHAR(16),
        ref_id INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
        paid_at TIMESTAMP,
        FOREIGN KEY (reservation_id) REFERENCES reservations (id)
    ) ENGINE = InnoDB;

-- کدهای تخفیف
CREATE TABLE
    discount_codes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(20) NOT NULL,
        product_id INT DEFAULT NULL,
        category_id INT DEFAULT NULL,
        instructor_id INT DEFAULT NULL,
        discount_percent TINYINT UNSIGNED NOT NULL,
        discount_max INT UNSIGNED NOT NULL,
        discount_constant INT UNSIGNED NOT NULL,
        capacity SMALLINT UNSIGNED NOT NULL,
        creator_id INT NOT NULL,
        expires_on TIMESTAMP NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products (id),
        FOREIGN KEY (creator_id) REFERENCES users (id),
        FOREIGN KEY (category_id) REFERENCES categories (id),
        FOREIGN KEY (instructor_id) REFERENCES instructor_id (id)
    ) ENGINE = InnoDB;

-- تیکت های پشتیبانی
CREATE TABLE
    support_tickets (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id INT UNSIGNED,
        user_id INT UNSIGNED NOT NULL,
        subject VARCHAR(50) NOT NULL,
        s code VARCHAR(10) NOT NULL,
        status ENUM (
            'pending',
            ' answered',
            'user-response',
            'finished'
        ) DEFAULT 'pending' NOT NULL,
        priority ENUM ('low', 'medium', 'high') DEFAULT 'medium' NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
        FOREIGN KEY (product_id) REFERENCES products (id),
        FOREIGN KEY (user_id) REFERENCES users (id)
    ) ENGINE = InnoDB;

-- پیام های تیکت پشتیبانی
CREATE TABLE
    support_ticket_messages (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT UNSIGNED,
        user_id INT UNSIGNED NOT NULL,
        read BOOLEAN DEFAULT FALSE NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
        FOREIGN KEY (ticket) REFERENCES support_tickets (id),
        FOREIGN KEY (user_id) REFERENCES users (id)
    ) ENGINE = InnoDB;

-- اعلان ها
CREATE TABLE
    notifications (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id INT UNSIGNED,
        reservation_id INT UNSIGNED,
        pay_id INT UNSIGNED,
        ticket_id INT UNSIGNED,
        user_id INT UNSIGNED NOT NULL,
        title VARCHAR(50) NOT NULL,
        message TEXT NOT NULL,
        type ENUM (
            'warning',
            'stern-warning',
            'ads',
            'offer',
            'notice',
            'update'
        ) DEFAULT 'notice' NOT NULL,
        urgent BOOLEAN DEFAULT FALSE NOT NULL,
        read BOOLEAN DEFAULT FALSE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
        FOREIGN KEY (product_id) REFERENCES products (id),
        FOREIGN KEY (reservation_id) REFERENCES reservations (id),
        FOREIGN KEY (pay_id) REFERENCES payments (id),
        FOREIGN KEY (ticket_id) REFERENCES support_tickets (id),
        FOREIGN KEY (user_id) REFERENCES users (id)
    ) ENGINE = InnoDB;

-- نظرات
CREATE TABLE
    reviews (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id INT UNSIGNED NOT NULL,
        student_name VARCHAR(255),
        avatar VARCHAR(255),
        rating TINYINT CHECK (rating BETWEEN 1 AND 5),
        comment TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
    );

-- گزارش ها
CREATE TABLE
    reports (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        reporter_id INT UNSIGNED NOT NULL,
        reported_user_id INT UNSIGNED,
        reported_product_id INT UNSIGNED,
        reported_instructor_id INT UNSIGNED,
        reason TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
        FOREIGN KEY (reporter_id) REFERENCES users (id),
        FOREIGN KEY (reported_user_id) REFERENCES users (id),
        FOREIGN KEY (reported_product_id) REFERENCES products (id),
        FOREIGN KEY (reported_instructor_id) REFERENCES instructors (id)
    ) ENGINE = InnoDB;
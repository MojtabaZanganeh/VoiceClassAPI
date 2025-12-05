-- کاربران
CREATE TABLE
    users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        uuid VARCHAR(36) UNIQUE NOT NULL,
        username VARCHAR(32) UNIQUE NOT NULL,
        phone VARCHAR(12) UNIQUE NOT NULL,
        email VARCHAR(254) UNIQUE,
        password TEXT NOT NULL,
        role ENUM ('user', 'instructor', 'admin') DEFAULT 'user' NOT NULL,
        avatar TEXT,
        is_active BOOLEAN DEFAULT TRUE NOT NULL,
        registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL
    ) ENGINE = InnoDB;

-- اطلاعات پروفایل
CREATE TABLE
    user_profiles (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED UNIQUE NOT NULL,
        first_name_fa VARCHAR(50),
        last_name_fa VARCHAR(50),
        gender ENUM ('male', 'female'),
        birth_date DATE,
        province VARCHAR(50),
        city VARCHAR(50),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE = InnoDB;

-- اطلاعات گواهی نامه
CREATE TABLE
    user_certificates (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED UNIQUE NOT NULL,
        first_name_en VARCHAR(50),
        last_name_en VARCHAR(50),
        father_name VARCHAR(50),
        national_id VARCHAR(20) UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users (id)
    ) ENGINE = InnoDB;

-- آدرس پستی
CREATE TABLE
    user_addresses (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED UNIQUE NOT NULL,
        province VARCHAR(50),
        city VARCHAR(50),
        full_address TEXT,
        postal_code VARCHAR(10),
        receiver_phone VARCHAR(12),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
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

-- اثر انگشت
CREATE TABLE
    authenticator_credentials (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        credential_id VARCHAR(255) UNIQUE NOT NULL,
        credential_data JSON NOT NULL,
        public_key LONGBLOB NOT NULL,
        sign_count INT UNSIGNED DEFAULT 0 NOT NULL,
        device_name VARCHAR(100) NULL,
        device_type ENUM ('mobile', 'desktop') NULL,
        user_agent TEXT NULL,
        aaguid VARCAR (255) NULL,
        attestation_format VARCAR (100) NULL,
        is_active BOOLEAN DEFAULT true NOT NULL,
        last_used_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
        deleted_at TIMESTAMP NULL,
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE = InnoDB;

-- لاگ ورود
CREATE TABLE
    security_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NULL DEFAULT NULL,
        action VARCHAR(100) NOT NULL,
        details JSON NULL,
        ip VARCHAR(45) NOT NULL,
        user_agent TEXT NULL,
        severity ENUM ('low', 'medium', 'high', 'critical') DEFAULT 'medium' NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id),
        INDEX idx_action (action),
        INDEX idx_ip (ip),
        INDEX idx_created_at (created_at),
        INDEX idx_severity (severity),
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
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
        uuid VARCHAR(36) UNIQUE NOT NULL,
        status ENUM (
            'not-completed',
            'need-approval',
            'verified',
            'rejected',
            'deleted',
            'admin-deleted'
        ) NOT NULL,
        instructor_active BOOLEAN DEFAULT TRUE NOT NULL,
        short_link VARCAR (6) UNIQUE NOT NULL,
        slug VARCHAR(100) UNIQUE NOT NULL,
        category_id INT UNSIGNED NOT NULL,
        instructor_id INT UNSIGNED NOT NULL,
        type ENUM ('course', 'book') NOT NULL,
        thumbnail VARCHAR(75) NOT NULL,
        title VARCHAR(75) NOT NULL,
        introduction VARCHAR(150) NOT NULL,
        description TEXT NOT NULL,
        what_you_learn JSON NOT NULL,
        requirements JSON,
        level ENUM ('beginner', 'intermediate', 'advanced', 'expert') NOT NULL,
        price INT UNSIGNED NOT NULL,
        discount_amount INT UNSIGNED DEFAULT 0,
        instructor_share_percent TINYINT UNSIGNED DEFAULT '70' NOT NULL,
        rating_avg FLOAT UNSIGNED DEFAULT 0 NOT NULL,
        rating_count INT UNSIGNED DEFAULT 0 NOT NULL,
        students INT UNSIGNED DEFAULT 0 NOT NULL,
        creator_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
        FOREIGN KEY (category_id) REFERENCES categories (id),
        FOREIGN KEY (instructor_id) REFERENCES instructors (id),
        FOREIGN KEY (creator_id) REFERENCES users (id)
    ) ENGINE = InnoDB;

-- جزئیات دوره
CREATE TABLE
    course_details (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id INT UNSIGNED NOT NULL,
        access_type ENUM ('recorded', 'online') NOT NULL,
        all_lessons_count INT UNSIGNED NOT NULL,
        duration INT UNSIGNED NOT NULL,
        record_progress TINYINT DEFAULT 0 NOT NULL,
        online_price INT UNSIGNED NOT NULL,
        online_discount_amount INT UNSIGNED NOT NULL,
        FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
    ) ENGINE = InnoDB;

-- جزئیات جزوه
CREATE TABLE
    book_details (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id INT UNSIGNED NOT NULL,
        access_type ENUM ('printed', 'digital') NOT NULL,
        pages INT UNSIGNED NOT NULL,
        format ENUM ('PDF', 'PowerPoint', 'EPUB') NOT NULL,
        size INT UNSIGNED NOT NULL,
        all_lessons_count INT UNSIGNED NOT NULL,
        printed_price INT UNSIGNED NOT NULL,
        printed_discount_amount INT UNSIGNED NOT NULL,
        demo_link TEXT NOT NULL,
        digital_link TEXT,
        FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
    ) ENGINE = InnoDB;

-- لینک های مرتبط
CREATE TABLE
    product_links (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id INT UNSIGNED NOT NULL,
        type ENUM ('online_class', 'related_book', 'support_group') NOT NULL,
        platform ENUM (
            'website',
            'eitaa',
            'bale',
            'soroush',
            'rubika',
            'gap',
            'igap',
            'telegram',
            'whatsapp',
            'other'
        ) DEFAULT 'website' NOT NULL,
        title VARCHAR(150) NOT NULL,
        url TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
    ) ENGINE = InnoDB;

-- زمان بندی کلاس آنلاین
CREATE TABLE
    online_course_schedules (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id INT UNSIGNED NOT NULL,
        type ENUM ('recurring', 'webinar') NOT NULL,
        days_of_week SET ('sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri') NULL,
        start_date DATE NULL,
        end_date DATE NULL,
        webinar_date DATE NULL,
        start_time TIME NULL,
        end_time TIME NULL,
        url TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
    ) ENGINE = InnoDB;

-- سرفصل ها
CREATE TABLE
    chapters (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id INT UNSIGNED NOT NULL,
        title VARCHAR(25) NOT NULL,
        lessons_count INT UNSIGNED DEFAULT 0 NOT NULL,
        chapter_length INT UNSIGNED DEFAULT 0 NOT NULL,
        FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
    ) ENGINE = InnoDB;

-- درس های فصل
CREATE TABLE
    chapter_lessons (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        chapter_id INT UNSIGNED NOT NULL,
        title VARCHAR(50) NOT NULL,
        length INT UNSIGNED DEFAULT 0 NOT NULL,
        free BOOLEAN DEFAULT FALSE NOT NULL,
        link TEXT,
        size SMALLINT,
        FOREIGN KEY (chapter_id) REFERENCES chapters (id) ON DELETE CASCADE
    ) ENGINE = InnoDB;

-- تمارین و امتحانات
CREATE TABLE
    assessments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        uuid VARCHAR(36) UNIQUE NOT NULL,
        product_id INT UNSIGNED NOT NULL,
        chapter_id INT UNSIGNED DEFAULT NULL,
        lesson_id INT UNSIGNED DEFAULT NULL,
        type ENUM ('exercise', 'exam') NOT NULL,
        is_required BOOLEAN DEFAULT FALSE NOT NULL,
        unlock_next BOOLEAN DEFAULT FALSE NOT NULL,
        format ENUM ('zip', 'test', 'essay', 'mixed') NOT NULL,
        title VARCHAR(100) NOT NULL,
        description TEXT NOT NULL,
        duration INT UNSIGNED DEFAULT NULL,
        max_score INT UNSIGNED NOT NULL,
        status ENUM (
            'not-started',
            'in-progress',
            'completed',
            'time-expired'
        ) NOT NULL DEFAULT 'not-started',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
        FOREIGN KEY (chapter_id) REFERENCES chapters (id) ON DELETE CASCADE,
        FOREIGN KEY (lesson_id) REFERENCES chapter_lessons (id) ON DELETE CASCADE
    ) ENGINE = InnoDB;

-- سوالات امتحان
CREATE TABLE
    assessment_questions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        assessment_id INT UNSIGNED NOT NULL,
        type ENUM ('test', 'essay') NOT NULL,
        question TEXT NOT NULL,
        score INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE CASCADE
    ) ENGINE = InnoDB;

-- گزینه های سوال تستی
CREATE TABLE
    assessment_question_options (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        question_id INT UNSIGNED NOT NULL,
        option_text VARCHAR(255) NOT NULL,
        is_correct BOOLEAN DEFAULT FALSE NOT NULL,
        FOREIGN KEY (question_id) REFERENCES assessment_questions (id) ON DELETE CASCADE
    ) ENGINE = InnoDB;

-- ارسالی های دانشجو مربوط به ارزیابی ها
CREATE TABLE
    assessment_submissions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        uuid VARCHAR(36) UNIQUE NOT NULL,
        assessment_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        file_link TEXT DEFAULT NULL,
        started_at TIMESTAMP NULL,
        submitted_at TIMESTAMP NULL,
        auto_score INT UNSIGNED DEFAULT 0 NOT NULL,
        manual_score INT UNSIGNED DEFAULT 0 NOT NULL,
        final_score INT UNSIGNED GENERATED ALWAYS AS (auto_score + manual_score) STORED,
        status ENUM ('pending', 'reviewing', 'completed') DEFAULT 'pending',
        FOREIGN KEY (assessment_id) REFERENCES assessments (id),
        FOREIGN KEY (user_id) REFERENCES users (id)
    ) ENGINE = InnoDB;

-- پاسخ های سوالات امتحان
CREATE TABLE
    assessment_submission_answers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        submission_id INT UNSIGNED NOT NULL,
        question_id INT UNSIGNED NOT NULL,
        answer_text TEXT NULL,
        selected_option_id INT UNSIGNED NULL,
        auto_correct BOOLEAN DEFAULT NULL,
        instructor_note TEXT NULL,
        FOREIGN KEY (submission_id) REFERENCES assessment_submissions (id) ON DELETE CASCADE,
        FOREIGN KEY (question_id) REFERENCES assessment_questions (id) ON DELETE CASCADE,
        FOREIGN KEY (selected_option_id) REFERENCES assessment_question_options (id)
    ) ENGINE = InnoDB;

-- مدرس ها
CREATE TABLE
    instructors (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        uuid VARCHAR(36) UNIQUE NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        status ENUM ('active', 'inactive', 'suspended') DEFAULT 'active' NOT NULL,
        slug VARCHAR(100) UNIQUE NOT NULL,
        professional_title VARCHAR(100) NOT NULL,
        bio TEXT NOT NULL,
        categories_id JSON NOT NULL,
        rating_avg FLOAT UNSIGNED DEFAULT 0 NOT NULL,
        rating_count INT UNSIGNED DEFAULT 0 NOT NULL,
        students INT UNSIGNED DEFAULT 0 NOT NULL,
        courses_taught INT UNSIGNED DEFAULT 0 NOT NULL,
        books_written INT UNSIGNED DEFAULT 0 NOT NULL,
        total_earnings BIGINT UNSIGNED DEFAULT 0 NOT NULL,
        unpaid_earnings BIGINT UNSIGNED DEFAULT 0 NOT NULL,
        registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users (id)
    ) ENGINE = InnoDB;

-- قراردادهای مدرس ها
CREATE TABLE
    instructor_contracts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        uuid VARCHAR(36) UNIQUE NOT NULL,
        instructor_id INT UNSIGNED NOT NULL,
        status ENUM (
            'pending-review',
            'approved',
            'rejected',
            'expired'
        ) DEFAULT 'pending-review' NOT NULL,
        file TEXT NOT NULL,
        expires_at TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
        FOREIGN KEY (instructor_id) REFERENCES instructors (id)
    ) ENGINE = InnoDB;

-- درآمدهای مدرس ها
CREATE TABLE
    instructor_earnings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        uuid VARCHAR(36) UNIQUE NOT NULL,
        instructor_id INT UNSIGNED NOT NULL,
        order_item_id INT UNSIGNED UNIQUE NOT NULL,
        amount BIGINT UNSIGNED NOT NULL,
        site_commission BIGINT UNSIGNED NOT NULL,
        total_price BIGINT UNSIGNED NOT NULL,
        status ENUM ('pending', 'paid', 'canceled') DEFAULT 'pending' NOT NULL,
        settled_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
        FOREIGN KEY (instructor_id) REFERENCES instructors (id),
        FOREIGN KEY (order_item_id) REFERENCES order_items (id)
    ) ENGINE = InnoDB;

-- سبد خرید
CREATE TABLE
    cart_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NOT NULL,
        access_type ENUM ('online', 'recorded', 'printed', 'digital') NOT NULL,
        quantity INT UNSIGNED DEFAULT 1 NOT NULL,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users (id),
        FOREIGN KEY (product_id) REFERENCES products (id)
    ) ENGINE = InnoDB;

-- سفارشات
CREATE TABLE
    orders (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        uuid VARCHAR(36) UNIQUE NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        code VARCHAR(10) NOT NULL,
        discount_code_id INT UNSIGNED,
        discount_amount INT UNSIGNED DEFAULT 0 NOT NULL,
        total_amount BIGINT UNSIGNED NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users (id),
        FOREIGN KEY (discount_code_id) REFERENCES discount_codes (id)
    ) ENGINE = InnoDB;

-- محصولات سفارش
CREATE TABLE
    order_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        uuid VARCHAR(36) UNIQUE NOT NULL,
        order_id INT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NOT NULL,
        status ENUM (
            'pending-pay',
            'pending-review',
            'sending',
            'completed',
            'rejected',
            'canceled'
        ) DEFAULT 'pending-pay' NOT NULL,
        access_type ENUM ('online', 'recorded', 'printed', 'digital') NOT NULL,
        quantity INT UNSIGNED DEFAULT 1 NOT NULL,
        price BIGINT UNSIGNED NOT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
        FOREIGN KEY (order_id) REFERENCES orders (id),
        FOREIGN KEY (product_id) REFERENCES products (id)
    ) ENGINE = InnoDB;

-- آدرس سفارش
CREATE TABLE
    order_addresses (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id INT UNSIGNED NOT NULL,
        province VARCHAR(50) NOT NULL,
        city VARCHAR(50) NOT NULL,
        postal_code VARCHAR(10) NOT NULL,
        full_address TEXT NOT NULL,
        receiver_name VARCHAR(50) NOT NULL,
        receiver_phone VARCHAR(12) NOT NULL,
        notes TEXT,
        FOREIGN KEY (order_id) REFERENCES orders (id)
    ) ENGINE = InnoDB;

-- پرداخت ها
CREATE TABLE
    transactions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        uuid VARCHAR(36) UNIQUE NOT NULL,
        order_id INT UNSIGNED NOT NULL,
        type ENUM ('online', 'card') NOT NULL,
        amount BIGINT UNSIGNED NOT NULL,
        status ENUM (
            'pending-pay',
            'need-approval',
            'rejected',
            'paid',
            'failed',
            'canceled'
        ) DEFAULT 'pending-pay' NOT NULL,
        authority VARCHAR(36),
        card_hash VARCHAR(64),
        card_pan VARCHAR(16),
        ref_id VARCHAR(20),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
        paid_at TIMESTAMP,
        FOREIGN KEY (order_id) REFERENCES orders (id)
    ) ENGINE = InnoDB;

-- دانشجوهای هر دوره
CREATE TABLE
    course_students (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        course_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        order_id INT UNSIGNED NOT NULL,
        progress TINYINT UNSIGNED DEFAULT 0 NOT NULL,
        enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
        FOREIGN KEY (course_id) REFERENCES products (id),
        FOREIGN KEY (user_id) REFERENCES users (id),
        FOREIGN KEY (order_id) REFERENCES orders (id)
    ) ENGINE = InnoDB;

-- کدهای تخفیف
CREATE TABLE
    discount_codes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(20) NOT NULL,
        product_id INT UNSIGNED DEFAULT NULL,
        category_id INT UNSIGNED DEFAULT NULL,
        instructor_id INT UNSIGNED DEFAULT NULL,
        discount_percent TINYINT UNSIGNED NOT NULL,
        discount_max INT UNSIGNED NOT NULL,
        discount_constant INT UNSIGNED NOT NULL,
        capacity SMALLINT UNSIGNED NOT NULL,
        creator_id INT UNSIGNED NOT NULL,
        expires_on TIMESTAMP NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products (id),
        FOREIGN KEY (creator_id) REFERENCES users (id),
        FOREIGN KEY (category_id) REFERENCES categories (id),
        FOREIGN KEY (instructor_id) REFERENCES instructors (id)
    ) ENGINE = InnoDB;

-- تیکت های پشتیبانی
CREATE TABLE
    support_tickets (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id INT UNSIGNED,
        user_id INT UNSIGNED NOT NULL,
        subject VARCHAR(50) NOT NULL,
        code VARCHAR(10) NOT NULL,
        status ENUM (
            'pending',
            'answered',
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
        `read` BOOLEAN DEFAULT FALSE NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
        FOREIGN KEY (ticket_id) REFERENCES support_tickets (id),
        FOREIGN KEY (user_id) REFERENCES users (id)
    ) ENGINE = InnoDB;

-- اعلان ها
CREATE TABLE
    notifications (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id INT UNSIGNED,
        order_id INT UNSIGNED,
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
        `read` BOOLEAN DEFAULT FALSE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
        FOREIGN KEY (product_id) REFERENCES products (id),
        FOREIGN KEY (order_id) REFERENCES orders (id),
        FOREIGN KEY (pay_id) REFERENCES transactions (id),
        FOREIGN KEY (ticket_id) REFERENCES support_tickets (id),
        FOREIGN KEY (user_id) REFERENCES users (id)
    ) ENGINE = InnoDB;

-- نظرات
CREATE TABLE
    reviews (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        uuid VARCHAR(36) UNIQUE NOT NULL,
        product_id INT UNSIGNED NOT NULL,
        order_item_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        student VARCAR (100) NOT NULL,
        avatar TEXT,
        rating TINYINT CHECK (rating BETWEEN 1 AND 5) NOT NULL,
        comment TEXT NOT NULL,
        status ENUM ('pending-review', 'verified', 'rejected') DEFAULT 'pending-review' NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
        FOREIGN KEY (order_item_id) REFERENCES order_items (id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE = InnoDB;

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

CREATE TABLE
    join_us_requests (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        uuid VARCHAR(36) UNIQUE NOT NULL,
        name VARCHAR(50) NOT NULL,
        phone VARCHAR(12) NOT NULL,
        email VARCHAR(255) NOT NULL,
        resume TEXT NOT NULL,
        demo_course_link TEXT NOT NULL,
        demo_book_link TEXT,
        status ENUM ('pending', 'interview', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE = InnoDB;
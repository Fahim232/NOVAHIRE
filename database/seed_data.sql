-- Comprehensive Dummy Data for NovaHire Development Environment
-- -------------------------------------------------------------

INSERT INTO companies (name, email, website, location, description) VALUES
('Quantum Innovations', 'contact@quantuminnovations.io', 'https://quantuminnovations.io', 'San Francisco, CA', 'Pioneering quantum computing algorithms for enterprise scale.'),
('Nexus Health Technologies', 'talent@nexushealth.com', 'https://nexushealth.com', 'Boston, MA', 'Developing next-generation patient diagnosis tools using automated learning models.'),
('CyberGuard Solutions', 'recruitment@cyberguard.net', 'https://cyberguard.net', 'Austin, TX', 'Autonomous cybersecurity monitoring and perimeter intrusion protection.'),
('Apex Financial Systems', 'jobs@apexfin.com', 'https://apexfin.com', 'New York, NY', 'Low-latency financial clearinghouse architecture and liquidity analytics.'),
('BlueOcean Logistics', 'hr@blueoceanlogistics.com', 'https://blueoceanlogistics.com', 'Seattle, WA', 'Automated freight forwarding, supply chain mapping, and routing algorithms.'),
('Vanguard Robotics', 'info@vanguardrobotics.org', 'https://vanguardrobotics.org', 'Pittsburgh, PA', 'Autonomous industrial automation, robotic arms, and warehouse fleet controllers.'),
('EcoGrid Energy', 'careers@ecogridenergy.de', 'https://ecogridenergy.de', 'Berlin, Germany', 'Decentralized smart grid orchestration for wind, solar, and battery storage clusters.'),
('Aether Studios', 'team@aetherstudios.game', 'https://aetherstudios.game', 'Montreal, Canada', 'Cross-platform real-time 3D gaming engines and interactive storytelling.'),
('Stratum Cloud Labs', 'hiring@stratumcloud.tech', 'https://stratumcloud.tech', 'Dublin, Ireland', 'Distributed serverless infrastructure and high-throughput vector storage.');

-- Hundreds of lines of job listings
INSERT INTO jobs (company_id, title, category, job_type, salary_range, requirements, description, status) VALUES
(1, 'Senior Backend Engineer (PHP / MySQL)', 'Engineering', 'Full-time', '$120,000 - $150,000', '5+ years PHP, PDO, MySQL performance tuning, Redis caching, microservices architecture.', 'Lead architecture on high-traffic seeker APIs and interview pipelines.', 'open'),
(1, 'Full Stack Web Developer', 'Engineering', 'Full-time', '$95,000 - $125,000', 'PHP 8+, JavaScript (ES6+), WebRTC protocols, TailwindCSS, REST API design.', 'Build interactive interview interfaces and real-time dashboard modules.', 'open'),
(2, 'Healthcare Data Analyst', 'Data', 'Full-time', '$80,000 - $110,000', 'SQL, Python, HIPAA data governance, ETL pipeline orchestration, Tableau.', 'Evaluate clinical data pipelines and build analytical outcome reports.', 'open'),
(2, 'Frontend UI/UX Specialist', 'Design', 'Full-time', '$85,000 - $115,000', 'Modern CSS, Figma, responsive design patterns, HTML5 accessibility.', 'Design applicant portals and interactive candidate profile workflows.', 'open'),
(3, 'Cybersecurity Incident Responder', 'Security', 'Remote', '$110,000 - $140,000', 'SIEM monitoring, penetration testing, threat hunting, Linux kernel audits.', 'Defend infrastructure against intrusion attempts and run penetration drills.', 'open'),
(3, 'DevOps & Reliability Engineer', 'Engineering', 'Full-time', '$115,000 - $145,000', 'Docker, Kubernetes, GitHub Actions CI/CD, Nginx reverse proxy, MySQL replication.', 'Oversee high availability and zero-downtime deployment workflows.', 'open'),
(4, 'Quantitative Market Analyst', 'Finance', 'Full-time', '$130,000 - $170,000', 'Stochastic calculus, high-frequency execution pipelines, C++ / Python, time-series SQL.', 'Formulate trading execution models and portfolio hedging strategies.', 'open'),
(4, 'Compliance & Risk Officer', 'Legal', 'Full-time', '$90,000 - $120,000', 'FINRA/SEC guidelines, regulatory filing protocols, internal risk audits.', 'Guarantee audit compliance across distributed trading platforms.', 'open'),
(5, 'Global Logistics Controller', 'Operations', 'Full-time', '$75,000 - $95,000', 'Supply chain routing, warehouse freight coordination, ERP inventory management.', 'Coordinate cross-border cargo fleets and schedule freight transfers.', 'open'),
(6, 'Robotics Firmware Engineer', 'Hardware', 'Full-time', '$125,000 - $160,000', 'Embedded C/C++, ROS2 framework, sensor fusion, real-time operating systems (RTOS).', 'Write deterministic controller firmware for multi-axis articulation units.', 'open'),
(7, 'Smart Grid Energy Architect', 'Energy', 'Remote', '$105,000 - $135,000', 'SCADA networks, IoT telemetry protocols, distributed load balancing.', 'Architect real-time energy routing between local storage nodes and main grid.', 'open'),
(8, 'Senior 3D Graphics Programmer', 'Gaming', 'Full-time', '$110,000 - $145,000', 'Vulkan/DirectX 12, HLSL shaders, real-time raytracing pipelines, memory optimisation.', 'Craft cutting-edge lighting shaders and spatial geometry pipelines.', 'open'),
(9, 'Distributed Storage Systems Architect', 'Cloud', 'Full-time', '$135,000 - $175,000', 'Raft consensus, vector database internals, NVMe-over-Fabrics, Linux I/O stack.', 'Develop high-performance vector lookup engines for generative AI pipelines.', 'open');

-- Mock Interview Question Bank
INSERT INTO interview_questions (category, question_text, expected_keywords, difficulty) VALUES
('PHP Core', 'Explain the differences between PDO and MySQLi in modern PHP development.', 'prepared statements, driver support, named parameters, exception handling', 'Medium'),
('Database', 'How do B-Tree indexes optimize SELECT queries, and when can they cause performance degradation?', 'cardinality, write amplification, leaf nodes, index scan, cache locality', 'Hard'),
('WebRTC', 'Walk through the STUN/TURN signaling sequence during peer-to-peer negotiation.', 'SDP offer/answer, ICE candidates, NAT traversal, relay fallback', 'Hard'),
('Architecture', 'What are the main trade-offs between monolithic MVC applications and decoupled microservices?', 'latency, deployment overhead, domain boundary, eventual consistency', 'Medium'),
('Security', 'How do you safeguard user authentication sessions against CSRF and Session Hijacking?', 'SameSite cookies, CSRF tokens, HttpOnly flag, session rotation', 'Medium'),
('Security', 'Detail the mechanics of SQL Injection and how parameterized statements prevent it.', 'input separation, SQL parser, binary protocol, escaping bypasses', 'Easy'),
('JavaScript', 'What is the Event Loop in JavaScript and how does the microtask queue differ from macrotasks?', 'call stack, promise resolution, setTimeout, process.nextTick, starvation', 'Hard'),
('System Design', 'How would you architect a rate-limiting middleware for an API accepting 10,000 req/sec?', 'Token bucket, Leaky bucket, Redis sorted sets, sliding window log', 'Hard');
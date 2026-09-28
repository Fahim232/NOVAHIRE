<?php
/**
 * NovaHire — Comprehensive Skill Assessments & Questions Seeder (Part 2 Expansion)
 */

require_once __DIR__ . '/../admin/dbcon.php';
global $con;

if (!$con) {
    die("Database connection failed.\n");
}

function getOrInsertSkill($con, $catId, $name, $desc) {
    $stmt = mysqli_prepare($con, "SELECT id FROM skills WHERE name = ?");
    mysqli_stmt_bind_param($stmt, "s", $name);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($res)) {
        return $row['id'];
    }
    $ins = mysqli_prepare($con, "INSERT INTO skills (category_id, name, description) VALUES (?, ?, ?)");
    mysqli_stmt_bind_param($ins, "iss", $catId, $name, $desc);
    mysqli_stmt_execute($ins);
    return mysqli_insert_id($con);
}

function getOrInsertAssessment($con, $skillId, $title, $desc, $timeLimit, $passingScore, $isPremium) {
    $stmt = mysqli_prepare($con, "SELECT id FROM skill_assessments WHERE skill_id = ? AND title = ?");
    mysqli_stmt_bind_param($stmt, "is", $skillId, $title);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($res)) {
        $upd = mysqli_prepare($con, "UPDATE skill_assessments SET description = ?, time_limit = ?, passing_score = ?, is_premium = ? WHERE id = ?");
        mysqli_stmt_bind_param($upd, "siiii", $desc, $timeLimit, $passingScore, $isPremium, $row['id']);
        mysqli_stmt_execute($upd);
        return $row['id'];
    }
    $ins = mysqli_prepare($con, "INSERT INTO skill_assessments (skill_id, title, description, time_limit, passing_score, is_premium, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
    mysqli_stmt_bind_param($ins, "issiii", $skillId, $title, $desc, $timeLimit, $passingScore, $isPremium);
    mysqli_stmt_execute($ins);
    return mysqli_insert_id($con);
}

function insertQuestions($con, $assessmentId, $questions) {
    $del = mysqli_prepare($con, "DELETE FROM skill_assessment_questions WHERE assessment_id = ?");
    mysqli_stmt_bind_param($del, "i", $assessmentId);
    mysqli_stmt_execute($del);

    $ins = mysqli_prepare($con, "INSERT INTO skill_assessment_questions (assessment_id, question, option1, option2, option3, option4, correct_answer) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($questions as $q) {
        mysqli_stmt_bind_param($ins, "issssss", $assessmentId, $q[0], $q[1], $q[2], $q[3], $q[4], $q[5]);
        mysqli_stmt_execute($ins);
    }
}

$extraAssessments = [
    // ------------------------------------------------------------------------
    // DATA SCIENCE & AI: Machine Learning
    // ------------------------------------------------------------------------
    [
        'cat_id' => 4,
        'skill' => 'Machine Learning',
        'desc' => 'Supervised and unsupervised learning, model evaluation, and feature engineering.',
        'assessment' => 'Machine Learning Fundamentals',
        'a_desc' => 'Demonstrate understanding of classification, regression, bias-variance tradeoff, cross-validation, and metrics.',
        'time' => 900,
        'pass' => 65,
        'pro' => 0,
        'questions' => [
            [
                'Which of the following is an example of an Unsupervised Learning algorithm?',
                'Linear Regression', 'Logistic Regression', 'K-Means Clustering', 'Random Forest Classifier', 'K-Means Clustering'
            ],
            [
                'What problem occurs when a machine learning model performs exceptionally well on training data but fails to generalize to unseen test data?',
                'Underfitting', 'Overfitting', 'Data normalization', 'Feature scaling', 'Overfitting'
            ],
            [
                'Which metric is particularly crucial when evaluating a classification model on an imbalanced dataset?',
                'Accuracy', 'Precision and Recall (F1-Score)', 'Mean Squared Error', 'R-Squared', 'Precision and Recall (F1-Score)'
            ],
            [
                'What is the role of the validation set in model training?',
                'To test final deployment speed', 'To tune hyperparameters and prevent overfitting during training', 'To store training labels', 'To backup weights', 'To tune hyperparameters and prevent overfitting during training'
            ],
            [
                'What technique helps reduce model complexity by penalizing large coefficient weights?',
                'Regularization (L1 Lasso / L2 Ridge)', 'Data augmentation', 'One-hot encoding', 'Min-max scaling', 'Regularization (L1 Lasso / L2 Ridge)'
            ]
        ]
    ],

    // ------------------------------------------------------------------------
    // DEVOPS & CLOUD: Docker
    // ------------------------------------------------------------------------
    [
        'cat_id' => 5,
        'skill' => 'Docker',
        'desc' => 'Application containerization, multi-stage builds, and Docker Compose orchestration.',
        'assessment' => 'Docker & Containerization Essentials',
        'a_desc' => 'Validate container lifecycle management, Dockerfiles, volumes, port mapping, and image optimization.',
        'time' => 800,
        'pass' => 60,
        'pro' => 0,
        'questions' => [
            [
                'What is the main difference between a Docker Container and a Virtual Machine?',
                'Containers virtualize hardware; VMs virtualize OS',
                'Containers share the host OS kernel and are lightweight; VMs package a full guest OS',
                'Containers require dedicated RAM reservations',
                'There is no functional difference',
                'Containers share the host OS kernel and are lightweight; VMs package a full guest OS'
            ],
            [
                'Which Dockerfile instruction specifies the default command executed when a container starts?',
                'RUN', 'FROM', 'CMD', 'WORKDIR', 'CMD'
            ],
            [
                'How do you persist database data between container restarts and removals in Docker?',
                'Using Docker Volumes or Bind Mounts', 'Writing to /tmp inside container', 'Exporting logs', 'Rebuilding the image each time', 'Using Docker Volumes or Bind Mounts'
            ],
            [
                'Which CLI command stops and removes all containers, networks, and volumes defined in a docker-compose.yml file?',
                'docker-compose stop', 'docker-compose down', 'docker-compose kill', 'docker-compose clean', 'docker-compose down'
            ],
            [
                'What flag in "docker run -d -p 8080:80 nginx" detaches the container to run in background mode?',
                '-p', '-d', '-b', '--detach-only', '-d'
            ]
        ]
    ],

    // ------------------------------------------------------------------------
    // WEB DEVELOPMENT: TypeScript
    // ------------------------------------------------------------------------
    [
        'cat_id' => 3,
        'skill' => 'TypeScript',
        'desc' => 'Static typing for JavaScript, generics, interfaces, enums, and type inference.',
        'assessment' => 'TypeScript Core Competency',
        'a_desc' => 'Evaluate your ability to write type-safe code, leverage interfaces, discriminated unions, and utility types.',
        'time' => 900,
        'pass' => 65,
        'pro' => 0,
        'questions' => [
            [
                'What is the purpose of TypeScript in frontend and backend development?',
                'To replace JavaScript in the browser engine', 'To provide static type checking during development to catch errors early', 'To run code faster on server CPUs', 'To eliminate HTML/CSS', 'To provide static type checking during development to catch errors early'
            ],
            [
                'Which TypeScript utility type constructs a type with all properties of T set to optional?',
                'Required<T>', 'Readonly<T>', 'Partial<T>', 'Pick<T>', 'Partial<T>'
            ],
            [
                'What is the type of variable declared as: let val: unknown; ?',
                'A type that can be accessed without any type checking', 'A type-safe counterpart of any where operations require prior type narrowing', 'An alias for null', 'A compiler warning', 'A type-safe counterpart of any where operations require prior type narrowing'
            ],
            [
                'What keyword is used to define generic type parameters in functions or classes?',
                '<T>', '[T]', '{T}', 'generic T', '<T>'
            ],
            [
                'What is the difference between an Interface and a Type alias in TypeScript?',
                'Interfaces can be merged through declaration merging; Type aliases cannot be merged via re-declaration',
                'Interfaces cannot describe objects',
                'Type aliases only work with numbers',
                'They have no syntax or semantic differences',
                'Interfaces can be merged through declaration merging; Type aliases cannot be merged via re-declaration'
            ]
        ]
    ],

    // ------------------------------------------------------------------------
    // PROGRAMMING: Java
    // ------------------------------------------------------------------------
    [
        'cat_id' => 1,
        'skill' => 'Java',
        'desc' => 'Object-oriented programming, JVM architecture, multi-threading, and Collections framework.',
        'assessment' => 'Java Core & OOP Assessment',
        'a_desc' => 'Test core Java proficiencies including inheritance, polymorphism, abstract classes, collections, and streams.',
        'time' => 900,
        'pass' => 65,
        'pro' => 0,
        'questions' => [
            [
                'Which Java collection class guarantees unique elements and does not maintain insertion order by default?',
                'ArrayList', 'LinkedList', 'HashSet', 'Vector', 'HashSet'
            ],
            [
                'What is the purpose of the final keyword when applied to a class in Java?',
                'It makes all variables static', 'It prevents the class from being extended / subclassed', 'It automatically deletes objects from RAM', 'It prevents instantiation', 'It prevents the class from being extended / subclassed'
            ],
            [
                'What component of the Java platform is responsible for automatically reclaiming unused memory?',
                'JVM Compiler', 'Garbage Collector (GC)', 'Class Loader', 'Bytecode Verifier', 'Garbage Collector (GC)'
            ],
            [
                'What exception is thrown when attempting to access a method on an uninitialized object reference?',
                'ClassCastException', 'NullPointerException', 'IllegalArgumentException', 'IndexOutOfBoundsException', 'NullPointerException'
            ],
            [
                'Which interface must a class implement to allow its instances to be sorted using Collections.sort()?',
                'Comparable', 'Cloneable', 'Serializable', 'Iterable', 'Comparable'
            ]
        ]
    ],

    // ------------------------------------------------------------------------
    // SOFTWARE ENGINEERING: Data Structures & Algorithms
    // ------------------------------------------------------------------------
    [
        'cat_id' => 6,
        'skill' => 'Data Structures',
        'desc' => 'Arrays, linked lists, trees, hash tables, sorting algorithms, and complexity analysis.',
        'assessment' => 'Data Structures & Algorithms',
        'a_desc' => 'Test your knowledge of time and space complexity (Big-O), search algorithms, trees, and graph traversals.',
        'time' => 1200,
        'pass' => 70,
        'pro' => 1,
        'questions' => [
            [
                'What is the average time complexity of searching for an element in a balanced Binary Search Tree (BST)?',
                'O(1)', 'O(log n)', 'O(n)', 'O(n log n)', 'O(log n)'
            ],
            [
                'What is the worst-case time complexity of QuickSort when a poor pivot is repeatedly chosen?',
                'O(n)', 'O(n log n)', 'O(n^2)', 'O(2^n)', 'O(n^2)'
            ],
            [
                'Which data structure follows the Last-In, First-Out (LIFO) order of operations?',
                'Queue', 'Stack', 'Linked List', 'Priority Queue', 'Stack'
            ],
            [
                'What is the average time complexity of lookups in a well-distributed Hash Table?',
                'O(1)', 'O(log n)', 'O(n)', 'O(n^2)', 'O(1)'
            ],
            [
                'Which algorithm is typically used to find the shortest path between nodes in a weighted graph with non-negative edges?',
                'Breadth-First Search (BFS)', 'Depth-First Search (DFS)', "Dijkstra's Algorithm", "Kruskal's Algorithm", "Dijkstra's Algorithm"
            ]
        ]
    ]
];

foreach ($extraAssessments as $item) {
    $skillId = getOrInsertSkill($con, $item['cat_id'], $item['skill'], $item['desc']);
    $assessmentId = getOrInsertAssessment($con, $skillId, $item['assessment'], $item['a_desc'], $item['time'], $item['pass'], $item['pro']);
    insertQuestions($con, $assessmentId, $item['questions']);
    $qCount = count($item['questions']);
    echo "✓ Seeded extra assessment: '{$item['assessment']}' ({$item['skill']}) with {$qCount} questions.\n";
}

echo "Expansion complete!\n";

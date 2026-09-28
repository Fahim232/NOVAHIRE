<?php
/**
 * NovaHire — Rich Skill Assessments & Questions Seeder
 * Populates comprehensive categories, skills, assessments, and real-world MCQs.
 */

require_once __DIR__ . '/../admin/dbcon.php';
global $con;

if (!$con) {
    die("Database connection failed.\n");
}

echo "Starting assessment & questions enrichment...\n";

// 1. Categories
$categories = [
    1 => ['name' => 'Programming', 'icon' => 'fas fa-code'],
    2 => ['name' => 'Database', 'icon' => 'fas fa-database'],
    3 => ['name' => 'Web Development', 'icon' => 'fas fa-globe'],
    4 => ['name' => 'Data Science & AI', 'icon' => 'fas fa-brain'],
    5 => ['name' => 'DevOps & Cloud', 'icon' => 'fas fa-cloud'],
    6 => ['name' => 'Software Engineering', 'icon' => 'fas fa-laptop-code'],
];

foreach ($categories as $id => $cat) {
    $stmt = mysqli_prepare($con, "INSERT INTO skill_categories (id, name, icon) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), icon = VALUES(icon)");
    mysqli_stmt_bind_param($stmt, "iss", $id, $cat['name'], $cat['icon']);
    mysqli_stmt_execute($stmt);
}
echo "✓ Categories initialized.\n";

// Helper function to get or insert skill
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

// Helper function to get or insert assessment
function getOrInsertAssessment($con, $skillId, $title, $desc, $timeLimit, $passingScore, $isPremium) {
    $stmt = mysqli_prepare($con, "SELECT id FROM skill_assessments WHERE skill_id = ? AND title = ?");
    mysqli_stmt_bind_param($stmt, "is", $skillId, $title);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($res)) {
        // Update details
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

// Helper function to insert questions
function insertQuestions($con, $assessmentId, $questions) {
    // Delete existing questions for fresh seed
    $del = mysqli_prepare($con, "DELETE FROM skill_assessment_questions WHERE assessment_id = ?");
    mysqli_stmt_bind_param($del, "i", $assessmentId);
    mysqli_stmt_execute($del);

    $ins = mysqli_prepare($con, "INSERT INTO skill_assessment_questions (assessment_id, question, option1, option2, option3, option4, correct_answer) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($questions as $q) {
        mysqli_stmt_bind_param($ins, "issssss", $assessmentId, $q[0], $q[1], $q[2], $q[3], $q[4], $q[5]);
        mysqli_stmt_execute($ins);
    }
}

// ============================================================================
// DATA DEFINITIONS (Assessments & Questions)
// ============================================================================

$assessmentsData = [
    // ------------------------------------------------------------------------
    // 1. PROGRAMMING: Python
    // ------------------------------------------------------------------------
    [
        'cat_id' => 1,
        'skill' => 'Python',
        'desc' => 'Python programming language for backend, automation, and data handling.',
        'assessment' => 'Python Standard Assessment',
        'a_desc' => 'Validate foundational and practical Python knowledge including data types, comprehensions, OOP, and error handling.',
        'time' => 900,
        'pass' => 60,
        'pro' => 0,
        'questions' => [
            [
                'Which built-in Python data structure is immutable?',
                'List', 'Dictionary', 'Tuple', 'Set', 'Tuple'
            ],
            [
                'What is the output of print(type([])) in Python 3?',
                "<class 'list'>", "<class 'array'>", "<type 'list'>", "<class 'dict'>", "<class 'list'>"
            ],
            [
                'How do you handle potential exceptions gracefully in Python?',
                'try / catch', 'try / except', 'do / catch', 'try / finally only', 'try / except'
            ],
            [
                'What does the *args parameter allow in a Python function definition?',
                'Keyword-only arguments', 'Arbitrary number of positional arguments', 'Required default values', 'Type hinting', 'Arbitrary number of positional arguments'
            ],
            [
                'Which keyword creates an anonymous inline function in Python?',
                'def', 'func', 'anonymous', 'lambda', 'lambda'
            ],
            [
                'What does list comprehension [x*2 for x in range(3)] evaluate to?',
                '[0, 2, 4]', '[2, 4, 6]', '[0, 1, 2]', '[2, 2, 2]', '[0, 2, 4]'
            ],
            [
                'Which method is automatically called when instantiating a class in Python?',
                '__start__', '__init__', '__new__', '__construct__', '__init__'
            ],
            [
                'What is the difference between "is" and "==" in Python?',
                '"is" checks equality of values, "==" checks memory identity',
                '"is" checks object identity in memory, "==" checks value equality',
                'They are identical aliases',
                '"is" is used only for strings',
                '"is" checks object identity in memory, "==" checks value equality'
            ]
        ]
    ],

    // ------------------------------------------------------------------------
    // 2. PROGRAMMING: JavaScript
    // ------------------------------------------------------------------------
    [
        'cat_id' => 1,
        'skill' => 'JavaScript',
        'desc' => 'Modern ECMAScript programming covering ES6+, async/await, closures, and DOM.',
        'assessment' => 'JavaScript Core Competency',
        'a_desc' => 'Test your knowledge of scopes, asynchronous patterns, event loop, promises, and modern ES6+ features.',
        'time' => 900,
        'pass' => 65,
        'pro' => 0,
        'questions' => [
            [
                'What is the result of typeof null in JavaScript?',
                "'null'", "'undefined'", "'object'", "'boolean'", "'object'"
            ],
            [
                'Which keyword declares a block-scoped variable that cannot be reassigned?',
                'var', 'let', 'const', 'static', 'const'
            ],
            [
                'What does the Array.prototype.map() method return?',
                'A single accumulated value', 'A new array with the results of calling a function on every element', 'The index of the first match', 'Boolean indicating existence', 'A new array with the results of calling a function on every element'
            ],
            [
                'What does Promise.all() do when one of the passed promises rejects?',
                'Waits for all others then resolves', 'Immediately rejects with the reason of the first rejected promise', 'Ignores the rejected promise', 'Returns undefined', 'Immediately rejects with the reason of the first rejected promise'
            ],
            [
                'What is a closure in JavaScript?',
                'A method to close browser tabs', 'A function bundled together with references to its surrounding lexical environment', 'A syntax error during execution', 'A private class field', 'A function bundled together with references to its surrounding lexical environment'
            ],
            [
                'How does JavaScript handle asynchronous tasks under the hood?',
                'Multi-threading on CPU cores', 'Single-threaded event loop with task and microtask queues', 'Operating system interrupts', 'Preemptive background threads', 'Single-threaded event loop with task and microtask queues'
            ],
            [
                'What is the output of 3 + "3" in JavaScript?',
                '6', '"33"', 'NaN', 'TypeError', '"33"'
            ]
        ]
    ],

    // ------------------------------------------------------------------------
    // 3. PROGRAMMING: PHP Backend
    // ------------------------------------------------------------------------
    [
        'cat_id' => 1,
        'skill' => 'PHP',
        'desc' => 'Modern PHP server-side scripting, OOP, security, and database integration.',
        'assessment' => 'PHP & Backend Architecture',
        'a_desc' => 'Prove practical competence in PHP 8+, PDO/MySQLi prepared statements, session management, and MVC architecture.',
        'time' => 900,
        'pass' => 60,
        'pro' => 0,
        'questions' => [
            [
                'Which superglobal variable stores uploaded file data in PHP?',
                '$_FILES', '$_POST', '$_UPLOAD', '$_SERVER', '$_FILES'
            ],
            [
                'How can SQL Injection be effectively prevented in PHP database interactions?',
                'Using addslashes() on all inputs', 'Using prepared statements with parameterized queries', 'Encoding inputs with base64', 'Validating email formats only', 'Using prepared statements with parameterized queries'
            ],
            [
                'What does the === operator do in PHP?',
                'Assigns a value', 'Compares value only', 'Compares both value and type for strict equality', 'Compares memory pointers', 'Compares both value and type for strict equality'
            ],
            [
                'Which function initiates or resumes a user session in PHP?',
                'session_start()', 'start_session()', 'session_init()', 'session_begin()', 'session_start()'
            ],
            [
                'What is the difference between require and include in PHP if the target file is missing?',
                'require throws a fatal error and halts execution; include throws a warning and continues',
                'include throws a fatal error; require only gives a notice',
                'Both are 100% identical in behavior',
                'require is used for images only',
                'require throws a fatal error and halts execution; include throws a warning and continues'
            ],
            [
                'Which function correctly hashes a password securely in modern PHP?',
                'md5()', 'sha1()', 'password_hash()', 'crypt_md5()', 'password_hash()'
            ]
        ]
    ],

    // ------------------------------------------------------------------------
    // 4. DATABASE: SQL
    // ------------------------------------------------------------------------
    [
        'cat_id' => 2,
        'skill' => 'SQL',
        'desc' => 'Structured Query Language for relational data querying, aggregation, and management.',
        'assessment' => 'SQL Fundamentals & Querying',
        'a_desc' => 'Evaluate your ability to write JOINs, GROUP BY aggregations, subqueries, and table constraints.',
        'time' => 900,
        'pass' => 65,
        'pro' => 0,
        'questions' => [
            [
                'Which SQL clause is used to filter rows after an aggregation with GROUP BY?',
                'WHERE', 'HAVING', 'ORDER BY', 'FILTER', 'HAVING'
            ],
            [
                'What type of JOIN returns all records from the left table and matched records from the right table?',
                'INNER JOIN', 'RIGHT JOIN', 'LEFT JOIN', 'CROSS JOIN', 'LEFT JOIN'
            ],
            [
                'Which SQL constraint prevents duplicate values within a specific column?',
                'NOT NULL', 'UNIQUE', 'CHECK', 'FOREIGN KEY', 'UNIQUE'
            ],
            [
                'How do you sort query results in descending order in SQL?',
                'SORT DESC', 'ORDER BY column DESC', 'GROUP BY column DESC', 'ORDER BY column DOWN', 'ORDER BY column DESC'
            ],
            [
                'What does the ACID acronym stand for in relational database transactions?',
                'Atomicity, Consistency, Isolation, Durability',
                'Accuracy, Completeness, Integrity, Dependability',
                'Association, Constraint, Indexing, Data',
                'Authorization, Control, Identity, Distribution',
                'Atomicity, Consistency, Isolation, Durability'
            ],
            [
                'Which statement is used to remove all rows from a table quickly without logging individual row deletions?',
                'DELETE * FROM table', 'DROP TABLE table', 'TRUNCATE TABLE table', 'REMOVE ALL table', 'TRUNCATE TABLE table'
            ],
            [
                'What is the purpose of an INDEX on a database column?',
                'To encrypt confidential data', 'To speed up data retrieval operations at the cost of slower writes', 'To enforce foreign key relationships', 'To automatically compress table storage', 'To speed up data retrieval operations at the cost of slower writes'
            ]
        ]
    ],

    // ------------------------------------------------------------------------
    // 5. DATABASE: MySQL
    // ------------------------------------------------------------------------
    [
        'cat_id' => 2,
        'skill' => 'MySQL',
        'desc' => 'MySQL relational database engine, performance indexing, and schema design.',
        'assessment' => 'MySQL Database Engineering',
        'a_desc' => 'Test advanced MySQL skills: InnoDB transactions, foreign keys, EXPLAIN query planning, and indexing strategies.',
        'time' => 1200,
        'pass' => 70,
        'pro' => 1,
        'questions' => [
            [
                'Which MySQL storage engine is the default and supports transactions and foreign keys?',
                'MyISAM', 'MEMORY', 'InnoDB', 'CSV', 'InnoDB'
            ],
            [
                'What MySQL command displays the execution plan of a query to assist in optimization?',
                'DESCRIBE QUERY', 'EXPLAIN', 'ANALYZE PLAN', 'SHOW PROFILE', 'EXPLAIN'
            ],
            [
                'What happens when ON DELETE CASCADE is set on a Foreign Key?',
                'Deleting the parent row automatically deletes all matching child rows',
                'Child rows are preserved and set to NULL',
                'The delete operation is rejected with an error',
                'Parent row cannot be updated',
                'Deleting the parent row automatically deletes all matching child rows'
            ],
            [
                'Which data type is best suited for storing monetary currency values precisely in MySQL?',
                'FLOAT', 'DOUBLE', 'DECIMAL', 'VARCHAR', 'DECIMAL'
            ],
            [
                'How can you view the character set and collation of a MySQL database?',
                'SHOW DATABASE STATUS', 'SHOW CREATE DATABASE db_name', 'EXPLAIN DATABASE', 'SHOW CHARSET TABLE', 'SHOW CREATE DATABASE db_name'
            ]
        ]
    ],

    // ------------------------------------------------------------------------
    // 6. DATABASE: MongoDB
    // ------------------------------------------------------------------------
    [
        'cat_id' => 2,
        'skill' => 'MongoDB',
        'desc' => 'NoSQL document database, BSON storage, aggregation pipelines, and sharding.',
        'assessment' => 'MongoDB & NoSQL Practical Test',
        'a_desc' => 'Prove your capability in building document data models, writing aggregation pipelines, and querying nested BSON structures.',
        'time' => 900,
        'pass' => 60,
        'pro' => 0,
        'questions' => [
            [
                'What format does MongoDB use internally to store documents?',
                'XML', 'JSON plain text', 'BSON (Binary JSON)', 'YAML', 'BSON (Binary JSON)'
            ],
            [
                'Which method is used to find a single document matching a query in MongoDB?',
                'db.collection.findOne()', 'db.collection.getOne()', 'db.collection.selectOne()', 'db.collection.fetch()', 'db.collection.findOne()'
            ],
            [
                'What is the primary key field name automatically generated by MongoDB for each document?',
                'id', '_id', 'uuid', 'doc_id', '_id'
            ],
            [
                'Which aggregation pipeline stage is used to filter documents in MongoDB?',
                '$project', '$group', '$match', '$sort', '$match'
            ],
            [
                'What operator updates an existing document field or adds it if it does not exist?',
                '$set', '$add', '$put', '$append', '$set'
            ]
        ]
    ],

    // ------------------------------------------------------------------------
    // 7. WEB DEVELOPMENT: React
    // ------------------------------------------------------------------------
    [
        'cat_id' => 3,
        'skill' => 'React',
        'desc' => 'React component lifecycle, Hooks, state management, and Virtual DOM.',
        'assessment' => 'React Developer Assessment',
        'a_desc' => 'Demonstrate mastery of functional components, React Hooks (useState, useEffect, useMemo), props, and component architecture.',
        'time' => 900,
        'pass' => 65,
        'pro' => 0,
        'questions' => [
            [
                'What is the Virtual DOM in React?',
                'A direct copy of the browser DOM updated natively',
                'A lightweight in-memory representation of the real DOM used for reconciliation',
                'A database storage engine for frontend states',
                'A server-side rendering compiler',
                'A lightweight in-memory representation of the real DOM used for reconciliation'
            ],
            [
                'Which React hook is used to manage component state in functional components?',
                'useFetch()', 'useState()', 'useContext()', 'useMemo()', 'useState()'
            ],
            [
                'When does useEffect with an empty dependency array [] execute?',
                'On every single state render', 'Only once when the component mounts', 'Whenever props change', 'Never', 'Only once when the component mounts'
            ],
            [
                'Why are "keys" required when rendering lists of elements in React?',
                'To style each list item uniquely', 'To help React identify which items have changed, been added, or removed for efficient reconciliation', 'To sort the array automatically', 'To enable CSS animations', 'To help React identify which items have changed, been added, or removed for efficient reconciliation'
            ],
            [
                'What is the correct way to update state that depends on the previous state value?',
                'setCount(count + 1)', 'setCount(prevCount => prevCount + 1)', 'this.state.count++', 'count = count + 1', 'setCount(prevCount => prevCount + 1)'
            ],
            [
                'What is the primary purpose of React.useMemo()?',
                'To memoize the result of an expensive calculation between renders', 'To store persistent state across browser reloads', 'To trigger DOM updates directly', 'To manage routing URLs', 'To memoize the result of an expensive calculation between renders'
            ]
        ]
    ],

    // ------------------------------------------------------------------------
    // 8. WEB DEVELOPMENT: Node.js
    // ------------------------------------------------------------------------
    [
        'cat_id' => 3,
        'skill' => 'Node.js',
        'desc' => 'Asynchronous event-driven JavaScript runtime for scalable backend services.',
        'assessment' => 'Node.js Backend Assessment',
        'a_desc' => 'Validate your understanding of Express middleware, Streams, Event Loop, REST APIs, and NPM package architecture.',
        'time' => 1000,
        'pass' => 65,
        'pro' => 0,
        'questions' => [
            [
                'Which engine powers Node.js JavaScript execution?',
                'SpiderMonkey', 'V8', 'JavaScriptCore', 'Chakra', 'V8'
            ],
            [
                'What is middleware in the Express.js framework?',
                'A database abstraction layer', 'A function that has access to request (req), response (res), and next middleware function', 'A hardware accelerator', 'A frontend template engine', 'A function that has access to request (req), response (res), and next middleware function'
            ],
            [
                'Which module in Node.js handles file system reading and writing?',
                'path', 'http', 'fs', 'net', 'fs'
            ],
            [
                'What does process.env allow you to access in Node.js?',
                'Hardware memory addresses', 'Operating system environment variables', 'CPU cache lines', 'Global browser variables', 'Operating system environment variables'
            ],
            [
                'What is the advantage of using Streams in Node.js for large files?',
                'They encrypt data automatically', 'They process data piece by piece without loading the entire file into RAM', 'They run on multiple GPU threads', 'They compress files into zip', 'They process data piece by piece without loading the entire file into RAM'
            ]
        ]
    ],

    // ------------------------------------------------------------------------
    // 9. WEB DEVELOPMENT: HTML5 & CSS3
    // ------------------------------------------------------------------------
    [
        'cat_id' => 3,
        'skill' => 'HTML/CSS',
        'desc' => 'Responsive web layout, Semantic HTML5, CSS Grid, Flexbox, and modern styling.',
        'assessment' => 'HTML5 & Responsive CSS3',
        'a_desc' => 'Test your core frontend fundamentals: Flexbox alignment, CSS Grid, semantic markup, and responsive media queries.',
        'time' => 800,
        'pass' => 60,
        'pro' => 0,
        'questions' => [
            [
                'Which HTML5 tag is semantic and intended to represent self-contained content like a blog post or article?',
                '<div>', '<article>', '<section>', '<aside>', '<article>'
            ],
            [
                'Which CSS property aligns flex items along the main axis?',
                'align-items', 'justify-content', 'align-content', 'flex-direction', 'justify-content'
            ],
            [
                'What is the difference between relative and absolute positioning in CSS?',
                'Relative is positioned relative to its normal flow position; Absolute is positioned relative to its closest positioned ancestor',
                'Absolute always stays fixed to the viewport',
                'Relative removes the element completely from document flow',
                'They behave identically in modern browsers',
                'Relative is positioned relative to its normal flow position; Absolute is positioned relative to its closest positioned ancestor'
            ],
            [
                'Which CSS property creates a two-dimensional grid layout with rows and columns?',
                'display: flex', 'display: grid', 'display: inline-block', 'display: table', 'display: grid'
            ],
            [
                'What unit is relative to the root element (<html>) font size?',
                'em', 'rem', 'vh', 'px', 'rem'
            ]
        ]
    ],

    // ------------------------------------------------------------------------
    // 10. DATA SCIENCE: Data Analysis
    // ------------------------------------------------------------------------
    [
        'cat_id' => 4,
        'skill' => 'Data Analysis',
        'desc' => 'Exploratory data analysis, statistical modeling, data cleaning, and reporting.',
        'assessment' => 'Data Analysis & Insights',
        'a_desc' => 'Evaluate skills in data aggregation, handling missing values, statistical metrics (mean, median, IQR), and business analytics.',
        'time' => 1000,
        'pass' => 60,
        'pro' => 0,
        'questions' => [
            [
                'Which statistical metric is least affected by extreme outliers in a dataset?',
                'Mean', 'Median', 'Variance', 'Range', 'Median'
            ],
            [
                'In Python pandas, which function reads a CSV file into a DataFrame?',
                'pd.read_csv()', 'pd.load_csv()', 'pd.parse_csv()', 'pd.open_csv()', 'pd.read_csv()'
            ],
            [
                'What is the purpose of data imputation during data cleaning?',
                'Deleting duplicate rows', 'Replacing missing data with substituted values', 'Encrypting column headers', 'Exporting charts to PDF', 'Replacing missing data with substituted values'
            ],
            [
                'What does a correlation coefficient of -0.92 indicate between two variables?',
                'No relationship', 'A strong positive linear relationship', 'A strong negative linear relationship', 'A calculation error', 'A strong negative linear relationship'
            ],
            [
                'Which type of visualization is most effective for displaying the distribution and quartiles of numerical data?',
                'Pie chart', 'Box plot (box-and-whisker)', 'Radar chart', 'Treemap', 'Box plot (box-and-whisker)'
            ]
        ]
    ],

    // ------------------------------------------------------------------------
    // 11. DEVOPS: Git & Version Control
    // ------------------------------------------------------------------------
    [
        'cat_id' => 5,
        'skill' => 'Git & Version Control',
        'desc' => 'Distributed version control, branching strategies, merge conflicts, and pull requests.',
        'assessment' => 'Git & Collaboration Assessment',
        'a_desc' => 'Demonstrate proficiency in branching, rebase vs merge, stash, commit conventions, and repository workflow management.',
        'time' => 800,
        'pass' => 60,
        'pro' => 0,
        'questions' => [
            [
                'Which command creates a new Git branch and switches to it in one step?',
                'git checkout -b <name>', 'git branch -new <name>', 'git switch -c <name>', 'git make <name>', 'git checkout -b <name>'
            ],
            [
                'What does git stash do?',
                'Permanently deletes all modified files', 'Temporarily shelves uncommitted changes so you can work on a clean directory', 'Pushes code to GitHub remote', 'Creates a merge conflict', 'Temporarily shelves uncommitted changes so you can work on a clean directory'
            ],
            [
                'What is the fundamental difference between git merge and git rebase?',
                'Merge creates a merge commit preserving exact history; rebase rewrites project history by moving the base of the branch',
                'Rebase always deletes the remote branch',
                'Merge is used only on GitHub',
                'They perform the exact same operation',
                'Merge creates a merge commit preserving exact history; rebase rewrites project history by moving the base of the branch'
            ],
            [
                'Which command stages all modified and deleted files for the next commit?',
                'git commit -a', 'git add -A', 'git push all', 'git stage -all', 'git add -A'
            ],
            [
                'How do you safely view a summary of recent commits on a repository?',
                'git log --oneline', 'git show all', 'git status -v', 'git list', 'git log --oneline'
            ]
        ]
    ],

    // ------------------------------------------------------------------------
    // 12. SOFTWARE ENGINEERING: Agile & Scrum
    // ------------------------------------------------------------------------
    [
        'cat_id' => 6,
        'skill' => 'Agile',
        'desc' => 'Agile delivery, Sprint ceremonies, user stories, Scrum roles, and continuous value.',
        'assessment' => 'Agile & Scrum Practitioner',
        'a_desc' => 'Assess your mastery of Agile principles, sprint planning, retrospectives, product backlogs, and cross-functional team delivery.',
        'time' => 800,
        'pass' => 60,
        'pro' => 0,
        'questions' => [
            [
                'What is the primary role of the Scrum Master?',
                'Assign tasks to developers daily', 'Facilitate Scrum events, remove impediments, and coach the team on Agile practices', 'Define business requirements and budget', 'Review code pull requests', 'Facilitate Scrum events, remove impediments, and coach the team on Agile practices'
            ],
            [
                'What ceremony is held at the end of a sprint to reflect on processes and discuss team improvements?',
                'Daily Standup', 'Sprint Review', 'Sprint Retrospective', 'Backlog Refinement', 'Sprint Retrospective'
            ],
            [
                'What constitutes a complete User Story format?',
                'Task, Priority, Due Date', 'As a [role], I want [action], so that [business value]', 'Title, Description, Bugs', 'Objective, Key Result, Metric', 'As a [role], I want [action], so that [business value]'
            ],
            [
                'Who is solely responsible for prioritizing the Product Backlog in Scrum?',
                'Product Owner', 'Development Team Lead', 'Scrum Master', 'Company CEO', 'Product Owner'
            ],
            [
                'What does a sprint burndown chart track over time?',
                'Number of hours worked by each engineer', 'Remaining effort / story points against sprint days', 'Customer satisfaction scores', 'Budget expenditures', 'Remaining effort / story points against sprint days'
            ]
        ]
    ],

    // ------------------------------------------------------------------------
    // 13. SOFTWARE ENGINEERING: QA & Software Testing
    // ------------------------------------------------------------------------
    [
        'cat_id' => 6,
        'skill' => 'Software Testing',
        'desc' => 'Unit testing, integration testing, test-driven development (TDD), and quality assurance.',
        'assessment' => 'Software Testing & QA Principles',
        'a_desc' => 'Validate fundamental QA knowledge including test pyramids, boundary value analysis, regression testing, and CI automated tests.',
        'time' => 900,
        'pass' => 65,
        'pro' => 0,
        'questions' => [
            [
                'What is the base of the Test Pyramid that should contain the highest number of automated tests?',
                'End-to-End (E2E) Tests', 'Unit Tests', 'Manual Exploratory Tests', 'UI Visual Tests', 'Unit Tests'
            ],
            [
                'What is Regression Testing?',
                'Testing the system for the first time', 'Re-running tests to ensure that new code changes have not broken existing functionality', 'Testing performance under high load', 'Testing database backup recovery', 'Re-running tests to ensure that new code changes have not broken existing functionality'
            ],
            [
                'What is the core cycle of Test-Driven Development (TDD)?',
                'Design -> Code -> Test', 'Red (write failing test) -> Green (write minimal code to pass) -> Refactor', 'Plan -> Deploy -> Audit', 'Code -> Commit -> Test', 'Red (write failing test) -> Green (write minimal code to pass) -> Refactor'
            ],
            [
                'What technique tests edge conditions at the minimum and maximum boundaries of valid input ranges?',
                'Boundary Value Analysis', 'Equivalence Partitioning', 'Smoke Testing', 'Monkey Testing', 'Boundary Value Analysis'
            ],
            [
                'What is a Mock object in automated unit testing?',
                'A defective module intended to trigger a bug', 'A simulated object that mimics the behavior of a real dependency in a controlled way', 'A copy of production database data', 'A user testing in staging', 'A simulated object that mimics the behavior of a real dependency in a controlled way'
            ]
        ]
    ]
];

// Seed the data
$totalNewAssessments = 0;
$totalNewQuestions = 0;

foreach ($assessmentsData as $item) {
    $skillId = getOrInsertSkill($con, $item['cat_id'], $item['skill'], $item['desc']);
    $assessmentId = getOrInsertAssessment($con, $skillId, $item['assessment'], $item['a_desc'], $item['time'], $item['pass'], $item['pro']);
    insertQuestions($con, $assessmentId, $item['questions']);
    $qCount = count($item['questions']);
    $totalNewAssessments++;
    $totalNewQuestions += $qCount;
    echo "✓ Seeded assessment: '{$item['assessment']}' ({$item['skill']}) with {$qCount} questions.\n";
}

echo "\n============================================\n";
echo "SUCCESS! Enriched {$totalNewAssessments} assessments with {$totalNewQuestions} total questions.\n";
echo "============================================\n";

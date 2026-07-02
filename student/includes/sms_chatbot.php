<?php
/**
 * 5G E-GURUSCHOOL - SMS Chatbot Widget (FIXED VERSION)
 * Include this file in your footer after the main content
 * Requires: $_SESSION['student_id'] to be set
 */

// Only show chatbot for logged-in students
if (!isset($_SESSION['student_id'])) {
    return;
}

// Fetch real student data from database
$chatbot_student = null;
$chatbot_unread_notifs = 0;
$chatbot_current_session = null;

if (isset($conn) || isset($GLOBALS['conn'])) {
    $db = $conn ?? $GLOBALS['conn'];

    // Get student basic info
    $stmt = $db->prepare("
        SELECT s.student_id, s.matric_number, s.first_name, s.last_name, 
               s.email, s.current_level, s.status, s.cgpa, s.current_session,
               p.program_name, d.department_name
        FROM students s
        LEFT JOIN programs p ON s.program_id = p.program_id
        LEFT JOIN departments d ON s.department_id = d.department_id
        WHERE s.student_id = ?
    ");
    $stmt->bind_param("i", $_SESSION['student_id']);
    $stmt->execute();
    $chatbot_student = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Get unread notifications count
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE student_id = ? AND is_read = 0");
    $stmt->bind_param("i", $_SESSION['student_id']);
    $stmt->execute();
    $chatbot_unread_notifs = $stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
    $stmt->close();

    // Get current academic session
    $session_result = $db->query("SELECT session_year, semester FROM academic_sessions WHERE is_current = 1 LIMIT 1");
    $chatbot_current_session = $session_result->fetch_assoc();
}

$student_name = $chatbot_student ? htmlspecialchars($chatbot_student['first_name'] . ' ' . $chatbot_student['last_name']) : 'Student';
$student_matric = $chatbot_student ? htmlspecialchars($chatbot_student['matric_number']) : '';
$student_level = $chatbot_student ? $chatbot_student['current_level'] : '';
$student_program = $chatbot_student ? htmlspecialchars($chatbot_student['program_name']) : '';
$student_dept = $chatbot_student ? htmlspecialchars($chatbot_student['department_name']) : '';
$student_cgpa = $chatbot_student ? $chatbot_student['cgpa'] : '0.00';
$student_status = $chatbot_student ? $chatbot_student['status'] : 'Active';
$student_email = $chatbot_student ? htmlspecialchars($chatbot_student['email']) : '';
$current_session = $chatbot_current_session ? $chatbot_current_session['session_year'] : '2025/2026';
$current_semester = $chatbot_current_session ? ($chatbot_current_session['semester'] == 1 ? 'First' : 'Second') : 'First';
$student_first_name = $chatbot_student ? htmlspecialchars($chatbot_student['first_name']) : 'Student';
?>

<!-- Chatbot Widget -->
<div class="eguru-chatbot" id="eguruChatbot">
    <!-- Chat Window -->
    <div class="eguru-chat-window" id="eguruChatWindow">
        <!-- Header -->
        <div class="eguru-chat-header">
            <div class="eguru-bot-avatar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/>
                    <path d="M19 10v2a7 7 0 0 1-14 0v-2"/>
                    <line x1="12" y1="19" x2="12" y2="22"/>
                    <line x1="8" y1="22" x2="16" y2="22"/>
                </svg>
            </div>
            <div class="eguru-header-info">
                <h3>AI Assistant</h3>
                <p><span class="eguru-status-dot"></span>Online now</p>
            </div>
            <div class="eguru-header-actions">
                <button class="eguru-header-btn" onclick="eguruClearChat()" title="Clear conversation">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="3 6 5 6 21 6"/>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                    </svg>
                </button>
                <button class="eguru-header-btn" onclick="eguruToggleChat()" title="Close">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Student Context Bar -->
        <div class="eguru-context-bar">
            <div class="eguru-context-item">
                <span class="eguru-context-label">Matric</span>
                <span class="eguru-context-value"><?php echo $student_matric; ?></span>
            </div>
            <div class="eguru-context-divider"></div>
            <div class="eguru-context-item">
                <span class="eguru-context-label">Level</span>
                <span class="eguru-context-value"><?php echo $student_level; ?>L</span>
            </div>
            <div class="eguru-context-divider"></div>
            <div class="eguru-context-item">
                <span class="eguru-context-label">Session</span>
                <span class="eguru-context-value"><?php echo $current_session; ?></span>
            </div>
            <div class="eguru-context-divider"></div>
            <div class="eguru-context-item">
                <span class="eguru-context-label">CGPA</span>
                <span class="eguru-context-value eguru-cgpa"><?php echo $student_cgpa; ?></span>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="eguru-quick-actions">
            <button class="eguru-quick-btn" onclick="eguruSendQuick('My results')">
                <span>📊</span> Results
            </button>
            <button class="eguru-quick-btn" onclick="eguruSendQuick('My fees')">
                <span>💰</span> Fees
            </button>
            <button class="eguru-quick-btn" onclick="eguruSendQuick('My courses')">
                <span>📚</span> Courses
            </button>
            <button class="eguru-quick-btn" onclick="eguruSendQuick('My profile')">
                <span>👤</span> Profile
            </button>
            <button class="eguru-quick-btn" onclick="eguruSendQuick('Notifications')">
                <span>🔔</span> Alerts
                <?php if ($chatbot_unread_notifs > 0): ?>
                <span class="eguru-quick-badge"><?php echo $chatbot_unread_notifs; ?></span>
                <?php endif; ?>
            </button>
        </div>

        <!-- Messages Area -->
        <div class="eguru-chat-messages" id="eguruChatMessages">
            <div class="eguru-welcome">
                <div class="eguru-welcome-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/>
                        <path d="M19 10v2a7 7 0 0 1-14 0v-2"/>
                        <line x1="12" y1="19" x2="12" y2="22"/>
                        <line x1="8" y1="22" x2="16" y2="22"/>
                    </svg>
                </div>
                <h2>Hello, <?php echo $student_first_name; ?>! 👋</h2>
                <p>I am your E-Guru Assistant. I can help you check results, fees, courses, and more.</p>
                <div class="eguru-welcome-hints">
                    <span class="eguru-hint">Try: "Show my results"</span>
                    <span class="eguru-hint">or: "What fees do I owe?"</span>
                </div>
            </div>
        </div>

        <!-- Typing Indicator -->
        <div class="eguru-typing" id="eguruTyping">
            <div class="eguru-typing-dots">
                <span></span><span></span><span></span>
            </div>
            <span class="eguru-typing-text">Assistant is typing...</span>
        </div>

        <!-- Input Area -->
        <div class="eguru-chat-input-area">
            <div class="eguru-input-wrapper">
                <input 
                    type="text" 
                    class="eguru-chat-input" 
                    id="eguruChatInput" 
                    placeholder="Ask me anything..."
                    autocomplete="off"
                    onkeypress="eguruHandleKey(event)"
                >
                <button class="eguru-send-btn" id="eguruSendBtn" onclick="eguruSendMessage()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="22" y1="2" x2="11" y2="13"/>
                        <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                    </svg>
                </button>
            </div>
            <div class="eguru-input-hint">Press Enter to send</div>
        </div>
    </div>

    <!-- Toggle Button -->
    <button class="eguru-chat-toggle" id="eguruChatToggle" onclick="eguruToggleChat()">
        <div class="eguru-toggle-icon">
            <svg class="eguru-icon-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            </svg>
            <svg class="eguru-icon-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="6" x2="6" y2="18"/>
                <line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
        </div>
        <?php if ($chatbot_unread_notifs > 0): ?>
        <span class="eguru-toggle-badge"><?php echo $chatbot_unread_notifs; ?></span>
        <?php endif; ?>
    </button>
</div>

<style>
/* ============================================
   E-GURU CHATBOT - Professional Styles
   Matches 5G E-GURUSCHOOL Design System
   Primary: #3f749c (Blue) | Accent: #c5ea4f (Lime)
   ============================================ */

.eguru-chatbot {
    position: fixed;
    bottom: 25px;
    right: 25px;
    z-index: 9999;
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    --eguru-primary: #3f749c;
    --eguru-primary-dark: #2a5a7a;
    --eguru-primary-light: #5a9bc4;
    --eguru-primary-soft: #e8f2f8;
    --eguru-accent: #c5ea4f;
    --eguru-accent-light: #d4f07a;
    --eguru-danger: #f44336;
    --eguru-warning: #ff9800;
    --eguru-success: #7cb342;
    --eguru-text: #2c3e50;
    --eguru-text-light: #7f8c8d;
    --eguru-bg: #f8f9fa;
    --eguru-white: #ffffff;
    --eguru-shadow: 0 8px 32px rgba(63, 116, 156, 0.15);
    --eguru-shadow-lg: 0 12px 40px rgba(63, 116, 156, 0.2);
    --eguru-radius: 16px;
    --eguru-radius-sm: 12px;
}

/* Toggle Button */
.eguru-chat-toggle {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--eguru-primary) 0%, var(--eguru-primary-dark) 100%);
    border: 3px solid var(--eguru-white);
    cursor: pointer;
    box-shadow: var(--eguru-shadow-lg);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    outline: none;
}

.eguru-chat-toggle:hover {
    transform: scale(1.08) rotate(5deg);
    box-shadow: 0 14px 48px rgba(63, 116, 156, 0.3);
}

.eguru-chat-toggle:active {
    transform: scale(0.95);
}

.eguru-toggle-icon {
    width: 28px;
    height: 28px;
    position: relative;
}

.eguru-toggle-icon svg {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    stroke: var(--eguru-white);
    transition: all 0.3s ease;
}

.eguru-icon-close {
    opacity: 0;
    transform: rotate(-90deg);
}

.eguru-chatbot.open .eguru-icon-open {
    opacity: 0;
    transform: rotate(90deg);
}

.eguru-chatbot.open .eguru-icon-close {
    opacity: 1;
    transform: rotate(0deg);
}

.eguru-toggle-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    background: var(--eguru-danger);
    color: var(--eguru-white);
    border-radius: 50%;
    min-width: 22px;
    height: 22px;
    font-size: 11px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 6px;
    border: 2px solid var(--eguru-white);
    animation: eguruPulse 2s infinite;
}

@keyframes eguruPulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(244, 67, 54, 0.4); }
    50% { box-shadow: 0 0 0 8px rgba(244, 67, 54, 0); }
}

/* Chat Window */
.eguru-chat-window {
    position: absolute;
    bottom: 85px;
    right: 0;
    width: 400px;
    height: 600px;
    background: var(--eguru-white);
    border-radius: var(--eguru-radius);
    box-shadow: var(--eguru-shadow-lg);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
    opacity: 0;
    transform: translateY(15px) scale(0.96);
    pointer-events: none;
    border: 1px solid rgba(63, 116, 156, 0.1);
}

.eguru-chatbot.open .eguru-chat-window {
    opacity: 1;
    transform: translateY(0) scale(1);
    pointer-events: all;
}

/* Header */
.eguru-chat-header {
    background: linear-gradient(135deg, var(--eguru-primary) 0%, var(--eguru-primary-dark) 100%);
    color: var(--eguru-white);
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    flex-shrink: 0;
}

.eguru-bot-avatar {
    width: 44px;
    height: 44px;
    border-radius: var(--eguru-radius-sm);
    background: rgba(255,255,255,0.15);
    backdrop-filter: blur(10px);
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid rgba(255,255,255,0.2);
}

.eguru-bot-avatar svg {
    width: 22px;
    height: 22px;
    stroke: var(--eguru-white);
}

.eguru-header-info {
    flex: 1;
}

.eguru-header-info h3 {
    font-size: 15px;
    font-weight: 600;
    margin-bottom: 3px;
    letter-spacing: 0.3px;
}

.eguru-header-info p {
    font-size: 12px;
    opacity: 0.85;
    display: flex;
    align-items: center;
    gap: 6px;
}

.eguru-status-dot {
    width: 7px;
    height: 7px;
    background: var(--eguru-accent);
    border-radius: 50%;
    box-shadow: 0 0 8px var(--eguru-accent);
    animation: eguruBlink 2s infinite;
}

@keyframes eguruBlink {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.eguru-header-actions {
    display: flex;
    gap: 6px;
}

.eguru-header-btn {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    background: rgba(255,255,255,0.1);
    border: 1px solid rgba(255,255,255,0.15);
    color: var(--eguru-white);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    outline: none;
}

.eguru-header-btn:hover {
    background: rgba(255,255,255,0.2);
    transform: translateY(-1px);
}

.eguru-header-btn svg {
    width: 16px;
    height: 16px;
}

/* Context Bar */
.eguru-context-bar {
    background: linear-gradient(90deg, var(--eguru-primary-soft) 0%, #f0f7fc 100%);
    padding: 10px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    border-bottom: 1px solid rgba(63, 116, 156, 0.08);
    flex-shrink: 0;
    overflow-x: auto;
    scrollbar-width: none;
}

.eguru-context-bar::-webkit-scrollbar { display: none; }

.eguru-context-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    min-width: 60px;
}

.eguru-context-label {
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--eguru-text-light);
    font-weight: 600;
}

.eguru-context-value {
    font-size: 12px;
    font-weight: 700;
    color: var(--eguru-primary-dark);
}

.eguru-context-value.eguru-cgpa {
    color: var(--eguru-success);
}

.eguru-context-divider {
    width: 1px;
    height: 24px;
    background: rgba(63, 116, 156, 0.15);
    flex-shrink: 0;
}

/* Quick Actions */
.eguru-quick-actions {
    padding: 12px 16px;
    background: var(--eguru-bg);
    border-bottom: 1px solid rgba(63, 116, 156, 0.06);
    display: flex;
    gap: 8px;
    overflow-x: auto;
    scrollbar-width: none;
    flex-shrink: 0;
}

.eguru-quick-actions::-webkit-scrollbar { display: none; }

.eguru-quick-btn {
    white-space: nowrap;
    padding: 8px 14px;
    border: 1.5px solid rgba(63, 116, 156, 0.12);
    background: var(--eguru-white);
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    color: var(--eguru-text);
    display: flex;
    align-items: center;
    gap: 5px;
    outline: none;
    position: relative;
}

.eguru-quick-btn:hover {
    background: var(--eguru-primary);
    color: var(--eguru-white);
    border-color: var(--eguru-primary);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(63, 116, 156, 0.2);
}

.eguru-quick-btn span:first-child {
    font-size: 14px;
}

.eguru-quick-badge {
    position: absolute;
    top: -6px;
    right: -6px;
    background: var(--eguru-danger);
    color: var(--eguru-white);
    border-radius: 50%;
    min-width: 18px;
    height: 18px;
    font-size: 10px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid var(--eguru-white);
}

/* Messages Area */
.eguru-chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 16px;
    background: linear-gradient(180deg, #fafbfc 0%, var(--eguru-bg) 100%);
}

.eguru-chat-messages::-webkit-scrollbar {
    width: 5px;
}

.eguru-chat-messages::-webkit-scrollbar-track {
    background: transparent;
}

.eguru-chat-messages::-webkit-scrollbar-thumb {
    background: rgba(63, 116, 156, 0.2);
    border-radius: 10px;
}

.eguru-chat-messages::-webkit-scrollbar-thumb:hover {
    background: rgba(63, 116, 156, 0.35);
}

/* Welcome Screen */
.eguru-welcome {
    text-align: center;
    padding: 30px 10px;
    animation: eguruFadeUp 0.5s ease;
}

@keyframes eguruFadeUp {
    from { opacity: 0; transform: translateY(15px); }
    to { opacity: 1; transform: translateY(0); }
}

.eguru-welcome-icon {
    width: 72px;
    height: 72px;
    margin: 0 auto 20px;
    background: linear-gradient(135deg, var(--eguru-primary-soft) 0%, #e8f2f8 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 3px solid rgba(63, 116, 156, 0.1);
}

.eguru-welcome-icon svg {
    width: 36px;
    height: 36px;
    stroke: var(--eguru-primary);
}

.eguru-welcome h2 {
    color: var(--eguru-text);
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 8px;
}

.eguru-welcome p {
    color: var(--eguru-text-light);
    font-size: 13px;
    line-height: 1.6;
    margin-bottom: 16px;
}

.eguru-welcome-hints {
    display: flex;
    flex-direction: column;
    gap: 6px;
    align-items: center;
}

.eguru-hint {
    font-size: 12px;
    color: var(--eguru-primary);
    background: var(--eguru-primary-soft);
    padding: 6px 14px;
    border-radius: 20px;
    font-weight: 500;
    border: 1px dashed rgba(63, 116, 156, 0.2);
}

/* Messages */
.eguru-message {
    max-width: 88%;
    animation: eguruMsgSlide 0.35s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    flex-direction: column;
    gap: 4px;
}

@keyframes eguruMsgSlide {
    from { opacity: 0; transform: translateY(10px) scale(0.97); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

.eguru-message.bot {
    align-self: flex-start;
}

.eguru-message.user {
    align-self: flex-end;
}

.eguru-message-content {
    padding: 12px 16px;
    border-radius: 18px;
    font-size: 13.5px;
    line-height: 1.55;
    word-wrap: break-word;
    position: relative;
}

.eguru-message.bot .eguru-message-content {
    background: var(--eguru-white);
    color: var(--eguru-text);
    border-bottom-left-radius: 4px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04), 0 0 1px rgba(0,0,0,0.06);
    border: 1px solid rgba(63, 116, 156, 0.06);
}

.eguru-message.user .eguru-message-content {
    background: linear-gradient(135deg, var(--eguru-primary) 0%, var(--eguru-primary-dark) 100%);
    color: var(--eguru-white);
    border-bottom-right-radius: 4px;
    box-shadow: 0 4px 12px rgba(63, 116, 156, 0.2);
}

.eguru-message-time {
    font-size: 10px;
    color: var(--eguru-text-light);
    padding: 0 4px;
    opacity: 0.7;
}

.eguru-message.user .eguru-message-time {
    text-align: right;
}

/* Info Cards */
.eguru-info-card {
    background: linear-gradient(135deg, var(--eguru-primary-soft) 0%, #f5fafd 100%);
    border-radius: var(--eguru-radius-sm);
    padding: 14px;
    margin-top: 10px;
    border-left: 3px solid var(--eguru-primary);
    border: 1px solid rgba(63, 116, 156, 0.08);
    border-left-width: 3px;
}

.eguru-info-card h4 {
    color: var(--eguru-primary-dark);
    margin-bottom: 10px;
    font-size: 13px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
}

.eguru-info-card p {
    margin: 5px 0;
    font-size: 12.5px;
    color: #4a5568;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.eguru-info-card .eguru-label {
    font-weight: 600;
    color: var(--eguru-text);
}

.eguru-info-card .eguru-value {
    font-weight: 500;
}

.eguru-status-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.eguru-status-active { background: #e8f5e9; color: #2e7d32; }
.eguru-status-pending { background: #fff3e0; color: #ef6c00; }
.eguru-status-paid { background: #e8f5e9; color: #2e7d32; }
.eguru-status-overdue { background: #ffebee; color: #c62828; }

/* Data Tables */
.eguru-data-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    margin-top: 10px;
    font-size: 12px;
    background: var(--eguru-white);
    border-radius: var(--eguru-radius-sm);
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    border: 1px solid rgba(63, 116, 156, 0.08);
}

.eguru-data-table th {
    background: linear-gradient(135deg, var(--eguru-primary) 0%, var(--eguru-primary-dark) 100%);
    color: var(--eguru-white);
    padding: 10px 12px;
    text-align: left;
    font-weight: 600;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.eguru-data-table th:first-child { border-radius: var(--eguru-radius-sm) 0 0 0; }
.eguru-data-table th:last-child { border-radius: 0 var(--eguru-radius-sm) 0 0; }

.eguru-data-table td {
    padding: 9px 12px;
    border-bottom: 1px solid rgba(63, 116, 156, 0.06);
    color: #4a5568;
}

.eguru-data-table tr:last-child td {
    border-bottom: none;
}

.eguru-data-table tr:hover td {
    background: var(--eguru-primary-soft);
}

/* Grade colors */
.eguru-grade-a { color: #2e7d32; font-weight: 700; }
.eguru-grade-b { color: #689f38; font-weight: 700; }
.eguru-grade-c { color: #f9a825; font-weight: 700; }
.eguru-grade-d { color: #ef6c00; font-weight: 700; }
.eguru-grade-f { color: #c62828; font-weight: 700; }

/* Suggestion Chips */
.eguru-suggestions {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 10px;
}

.eguru-chip {
    padding: 6px 12px;
    background: var(--eguru-white);
    border-radius: 16px;
    font-size: 11.5px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    border: 1.5px solid rgba(63, 116, 156, 0.12);
    color: var(--eguru-primary-dark);
    outline: none;
}

.eguru-chip:hover {
    background: var(--eguru-primary);
    color: var(--eguru-white);
    border-color: var(--eguru-primary);
    transform: translateY(-1px);
    box-shadow: 0 3px 8px rgba(63, 116, 156, 0.15);
}

/* Typing Indicator */
.eguru-typing {
    display: none;
    align-items: center;
    gap: 10px;
    padding: 12px 20px;
    background: var(--eguru-white);
    border-top: 1px solid rgba(63, 116, 156, 0.06);
}

.eguru-typing.active {
    display: flex;
}

.eguru-typing-dots {
    display: flex;
    gap: 4px;
}

.eguru-typing-dots span {
    width: 7px;
    height: 7px;
    background: var(--eguru-primary-light);
    border-radius: 50%;
    animation: eguruTypingBounce 1.4s infinite ease-in-out both;
}

.eguru-typing-dots span:nth-child(1) { animation-delay: -0.32s; }
.eguru-typing-dots span:nth-child(2) { animation-delay: -0.16s; }

@keyframes eguruTypingBounce {
    0%, 80%, 100% { transform: scale(0.6); opacity: 0.5; }
    40% { transform: scale(1); opacity: 1; }
}

.eguru-typing-text {
    font-size: 12px;
    color: var(--eguru-text-light);
    font-style: italic;
}

/* Input Area */
.eguru-chat-input-area {
    padding: 14px 18px;
    background: var(--eguru-white);
    border-top: 1px solid rgba(63, 116, 156, 0.08);
    flex-shrink: 0;
}

.eguru-input-wrapper {
    display: flex;
    gap: 10px;
    align-items: center;
    background: var(--eguru-bg);
    border-radius: 24px;
    padding: 4px;
    border: 1.5px solid rgba(63, 116, 156, 0.1);
    transition: border-color 0.2s;
}

.eguru-input-wrapper:focus-within {
    border-color: var(--eguru-primary-light);
    box-shadow: 0 0 0 3px rgba(63, 116, 156, 0.08);
}

.eguru-chat-input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 10px 14px;
    font-size: 13.5px;
    outline: none;
    color: var(--eguru-text);
    font-family: inherit;
}

.eguru-chat-input::placeholder {
    color: var(--eguru-text-light);
    opacity: 0.7;
}

.eguru-send-btn {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--eguru-primary) 0%, var(--eguru-primary-dark) 100%);
    border: none;
    color: var(--eguru-white);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    outline: none;
    flex-shrink: 0;
}

.eguru-send-btn:hover {
    transform: scale(1.08);
    box-shadow: 0 4px 12px rgba(63, 116, 156, 0.3);
}

.eguru-send-btn:active {
    transform: scale(0.95);
}

.eguru-send-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

.eguru-send-btn svg {
    width: 18px;
    height: 18px;
}

.eguru-input-hint {
    text-align: center;
    font-size: 10px;
    color: var(--eguru-text-light);
    margin-top: 6px;
    opacity: 0.6;
}

/* Error Message */
.eguru-error {
    background: #ffebee !important;
    color: #c62828 !important;
    border-left: 3px solid var(--eguru-danger) !important;
}

/* Loading Spinner */
.eguru-spinner {
    width: 18px;
    height: 18px;
    border: 2px solid rgba(255,255,255,0.25);
    border-top-color: var(--eguru-white);
    border-radius: 50%;
    animation: eguruSpin 0.8s linear infinite;
}

@keyframes eguruSpin {
    to { transform: rotate(360deg); }
}

/* ============================================
   RESPONSIVE DESIGN
   ============================================ */

@media (max-width: 480px) {
    .eguru-chatbot {
        bottom: 15px;
        right: 15px;
    }

    .eguru-chat-window {
        width: calc(100vw - 30px);
        height: calc(100vh - 100px);
        position: fixed;
        bottom: 80px;
        right: 15px;
        left: 15px;
        border-radius: var(--eguru-radius-sm);
    }

    .eguru-chat-toggle {
        width: 56px;
        height: 56px;
    }

    .eguru-context-bar {
        padding: 8px 12px;
    }

    .eguru-context-item {
        min-width: 50px;
    }

    .eguru-context-value {
        font-size: 11px;
    }

    .eguru-quick-actions {
        padding: 10px 12px;
    }

    .eguru-quick-btn {
        padding: 6px 12px;
        font-size: 11px;
    }

    .eguru-chat-messages {
        padding: 15px;
    }
}

@media (max-width: 360px) {
    .eguru-chat-window {
        width: calc(100vw - 20px);
        right: 10px;
        left: 10px;
    }

    .eguru-context-bar {
        gap: 4px;
    }

    .eguru-context-divider {
        display: none;
    }
}

/* Print styles */
@media print {
    .eguru-chatbot {
        display: none !important;
    }
}
</style>

<script>
     
(function() {
    'use strict';

    // Configuration
    var API_URL = 'chatbot_api.php';
    var STUDENT_ID =<?php echo $_SESSION['student_id']; ?>; 
    var isOpen = false;
    var isTyping = false;
    var messageHistory = [];

    // DOM Elements
    var chatbot = document.getElementById('eguruChatbot');
    var chatWindow = document.getElementById('eguruChatWindow');
    var chatMessages = document.getElementById('eguruChatMessages');
    var chatInput = document.getElementById('eguruChatInput');
    var sendBtn = document.getElementById('eguruSendBtn');
    var typingIndicator = document.getElementById('eguruTyping');

    // Toggle chat window
    window.eguruToggleChat = function() {
        isOpen = !isOpen;
        chatbot.classList.toggle('open', isOpen);

        if (isOpen) {
            chatInput.focus();
            var badge = document.querySelector('.eguru-toggle-badge');
            if (badge) badge.style.display = 'none';
        }
    };

    // Handle Enter key
    window.eguruHandleKey = function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            eguruSendMessage();
        }
    };

    // Send quick message
    window.eguruSendQuick = function(text) {
        chatInput.value = text;
        eguruSendMessage();
    };

    // Add message to chat
    function addMessage(text, sender, isHTML) {
        isHTML = isHTML || false;
        var welcome = chatMessages.querySelector('.eguru-welcome');
        if (welcome) welcome.remove();

        var msgDiv = document.createElement('div');
        msgDiv.className = 'eguru-message ' + sender;

        var content = document.createElement('div');
        content.className = 'eguru-message-content';

        if (isHTML) {
            content.innerHTML = text;
        } else {
            content.textContent = text;
        }

        var time = document.createElement('div');
        time.className = 'eguru-message-time';
        time.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

        msgDiv.appendChild(content);
        msgDiv.appendChild(time);
        chatMessages.appendChild(msgDiv);

        chatMessages.scrollTo({
            top: chatMessages.scrollHeight,
            behavior: 'smooth'
        });

        messageHistory.push({ sender: sender, text: text, time: new Date() });
    }

    // Show/hide typing
    function showTyping() {
        typingIndicator.classList.add('active');
        chatMessages.scrollTo({
            top: chatMessages.scrollHeight,
            behavior: 'smooth'
        });
    }

    function hideTyping() {
        typingIndicator.classList.remove('active');
    }

    // Send message to API
    window.eguruSendMessage = async function() {
        var text = chatInput.value.trim();
        if (!text || isTyping) return;

        addMessage(text, 'user');
        chatInput.value = '';

        isTyping = true;
        sendBtn.disabled = true;
        sendBtn.innerHTML = '<div class="eguru-spinner"></div>';
        showTyping();

        try {
            var response = await fetch(API_URL, {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ 
                    message: text, 
                    student_id: STUDENT_ID,
                    session_id: '<?php echo session_id(); ?>'
                })
            });

            if (!response.ok) {
                throw new Error('Network response was not ok');
            }

            var data = await response.json();
            hideTyping();

            if (data.success) {
                var formattedResponse = formatBotResponse(data);
                addMessage(formattedResponse, 'bot', true);
            } else {
                addMessage(data.message || 'Sorry, I could not process your request.', 'bot');
            }

        } catch (error) {
            hideTyping();
            console.error('Chatbot error:', error);
            var fallbackResponse = getDemoResponse(text);
            addMessage(fallbackResponse, 'bot', true);
        } finally {
            isTyping = false;
            sendBtn.disabled = false;
            sendBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>';
        }
    };

    // Format bot response with rich UI
    function formatBotResponse(data) {
        var html = '<div>' + (data.message || '') + '</div>';

        if (data.data) {
            if (data.data.courses && data.data.gpa !== undefined) {
                html += buildResultsCard(data.data);
            }
            else if (data.data.fees && data.data.total_fees !== undefined) {
                html += buildFeesCard(data.data);
            }
            else if (data.data.courses && data.data.total_courses !== undefined) {
                html += buildCoursesCard(data.data);
            }
            else if (data.data.name) {
                html += buildProfileCard(data.data);
            }
            else if (data.data.notifications) {
                html += buildNotificationsCard(data.data);
            }
        }

        if (data.suggestions && data.suggestions.length > 0) {
            html += '<div class="eguru-suggestions">';
            data.suggestions.forEach(function(suggestion) {
                var safeSuggestion = suggestion.replace(/'/g, "\'");
                html += '<button class="eguru-chip" onclick="eguruSendQuick(\'' + safeSuggestion + '\')">' + suggestion + '</button>';
            });
            html += '</div>';
        }

        return html;
    }

    function buildResultsCard(data) {
        var html = '<div class="eguru-info-card">';
        html += '<h4>📊 Academic Performance</h4>';
        html += '<p><span class="eguru-label">Session:</span> <span class="eguru-value">' + (data.session || '') + ' ' + (data.semester || '') + '</span></p>';
        html += '<p><span class="eguru-label">Level:</span> <span class="eguru-value">' + (data.level || '') + '</span></p>';
        html += '<p><span class="eguru-label">GPA:</span> <span class="eguru-value eguru-grade-a">' + (data.gpa || 'N/A') + '</span></p>';
        html += '<p><span class="eguru-label">CGPA:</span> <span class="eguru-value eguru-grade-a">' + (data.cgpa || 'N/A') + '</span></p>';
        html += '</div>';

        if (data.courses && data.courses.length > 0) {
            html += '<table class="eguru-data-table">';
            html += '<tr><th>Course</th><th>Score</th><th>Grade</th></tr>';
            data.courses.forEach(function(course) {
                var gradeClass = getGradeClass(course.grade);
                html += '<tr><td>' + course.course_code + '</td><td>' + course.total_score + '</td><td class="' + gradeClass + '">' + course.grade + '</td></tr>';
            });
            html += '</table>';
        }
        return html;
    }

    function buildFeesCard(data) {
        var html = '<div class="eguru-info-card">';
        html += '<h4>💰 Fee Summary</h4>';
        html += '<p><span class="eguru-label">Session:</span> <span class="eguru-value">' + (data.session || '') + '</span></p>';
        html += '<p><span class="eguru-label">Total Fees:</span> <span class="eguru-value">₦' + parseFloat(data.total_fees || 0).toLocaleString() + '</span></p>';
        html += '<p><span class="eguru-label">Amount Paid:</span> <span class="eguru-value">₦' + parseFloat(data.total_paid || 0).toLocaleString() + '</span></p>';
        var balanceColor = data.total_balance > 0 ? 'var(--eguru-danger)' : 'var(--eguru-success)';
        html += '<p><span class="eguru-label">Balance:</span> <span class="eguru-value" style="color:' + balanceColor + '">₦' + parseFloat(data.total_balance || 0).toLocaleString() + '</span></p>';
        var statusClass = 'eguru-status-' + (data.status || 'pending').toLowerCase();
        html += '<p><span class="eguru-label">Status:</span> <span class="eguru-status-badge ' + statusClass + '">' + data.status + '</span></p>';
        html += '</div>';

        if (data.fees && data.fees.length > 0) {
            html += '<table class="eguru-data-table">';
            html += '<tr><th>Type</th><th>Amount</th><th>Status</th></tr>';
            data.fees.forEach(function(fee) {
                var feeStatusClass = 'eguru-status-' + fee.status.toLowerCase();
                html += '<tr><td>' + fee.fee_type + '</td><td>₦' + parseFloat(fee.amount).toLocaleString() + '</td><td><span class="eguru-status-badge ' + feeStatusClass + '">' + fee.status + '</span></td></tr>';
            });
            html += '</table>';
        }
        return html;
    }

    function buildCoursesCard(data) {
        var html = '<div class="eguru-info-card">';
        html += '<h4>📚 Registered Courses</h4>';
        html += '<p><span class="eguru-label">Session:</span> <span class="eguru-value">' + (data.session || '') + ' ' + (data.semester || '') + '</span></p>';
        html += '<p><span class="eguru-label">Total Courses:</span> <span class="eguru-value">' + (data.total_courses || 0) + '</span></p>';
        html += '<p><span class="eguru-label">Total Units:</span> <span class="eguru-value">' + (data.total_units || 0) + '</span></p>';
        html += '</div>';

        if (data.courses && data.courses.length > 0) {
            html += '<table class="eguru-data-table">';
            html += '<tr><th>Code</th><th>Title</th><th>Units</th><th>Status</th></tr>';
            data.courses.forEach(function(course) {
                html += '<tr><td>' + course.course_code + '</td><td>' + course.course_title + '</td><td>' + course.credit_units + '</td><td><span class="eguru-status-badge eguru-status-active">' + course.registration_status + '</span></td></tr>';
            });
            html += '</table>';
        }
        return html;
    }

    function buildProfileCard(data) {
        var html = '<div class="eguru-info-card">';
        html += '<h4>👤 Student Profile</h4>';
        html += '<p><span class="eguru-label">Name:</span> <span class="eguru-value">' + (data.name || '') + '</span></p>';
        html += '<p><span class="eguru-label">Matric No:</span> <span class="eguru-value">' + (data.matric_number || '') + '</span></p>';
        html += '<p><span class="eguru-label">Email:</span> <span class="eguru-value">' + (data.email || '') + '</span></p>';
        html += '<p><span class="eguru-label">Program:</span> <span class="eguru-value">' + (data.program || '') + '</span></p>';
        html += '<p><span class="eguru-label">Department:</span> <span class="eguru-value">' + (data.department || '') + '</span></p>';
        html += '<p><span class="eguru-label">Level:</span> <span class="eguru-value">' + (data.level || '') + '</span></p>';
        html += '<p><span class="eguru-label">CGPA:</span> <span class="eguru-value eguru-grade-a">' + (data.cgpa || '') + '</span></p>';
        html += '<p><span class="eguru-label">Status:</span> <span class="eguru-status-badge eguru-status-active">' + (data.status || 'Active') + '</span></p>';
        html += '</div>';
        return html;
    }

    function buildNotificationsCard(data) {
        var html = '<div class="eguru-info-card">';
        html += '<h4>🔔 Notifications</h4>';
        html += '<p><span class="eguru-label">Unread:</span> <span class="eguru-value" style="color:var(--eguru-danger)">' + (data.unread_count || 0) + '</span></p>';
        html += '</div>';

        if (data.notifications && data.notifications.length > 0) {
            html += '<table class="eguru-data-table">';
            html += '<tr><th>Type</th><th>Message</th><th>Date</th></tr>';
            data.notifications.forEach(function(notif) {
                var notifDate = new Date(notif.sent_date).toLocaleDateString();
                html += '<tr><td>' + notif.notification_type + '</td><td>' + notif.title + '</td><td>' + notifDate + '</td></tr>';
            });
            html += '</table>';
        }
        return html;
    }

    function getGradeClass(grade) {
        if (!grade) return '';
        var g = grade.toUpperCase();
        if (g === 'A') return 'eguru-grade-a';
        if (g === 'B') return 'eguru-grade-b';
        if (g === 'C') return 'eguru-grade-c';
        if (g === 'D') return 'eguru-grade-d';
        if (g === 'F') return 'eguru-grade-f';
        return '';
    }

    function getDemoResponse(message) {
        var lowerMsg = message.toLowerCase();

        if (lowerMsg.indexOf('result') !== -1 || lowerMsg.indexOf('grade') !== -1) {
            var demoData = {
                session: '<?php echo $current_session; ?>',
                semester: '<?php echo $current_semester; ?>',
                level: '<?php echo $student_level; ?>',
                gpa: '4.25',
                cgpa: '<?php echo $student_cgpa; ?>',
                courses: [
                    { course_code: 'EDT301', total_score: '78', grade: 'A' },
                    { course_code: 'DIG301', total_score: '65', grade: 'B' },
                    { course_code: 'DIG304', total_score: '82', grade: 'A' }
                ]
            };
            return buildResultsCard(demoData) + '<div class="eguru-suggestions"><button class="eguru-chip" onclick="eguruSendQuick(\'Transcript\')">📄 Transcript</button><button class="eguru-chip" onclick="eguruSendQuick(\'Previous results\')">📅 Previous</button></div>';
        }

        if (lowerMsg.indexOf('fee') !== -1 || lowerMsg.indexOf('payment') !== -1) {
            var feeData = {
                session: '<?php echo $current_session; ?>',
                total_fees: 100000,
                total_paid: 50000,
                total_balance: 50000,
                status: 'Pending',
                fees: [
                    { fee_type: 'Tuition', amount: 100000, status: 'Partial' }
                ]
            };
            return buildFeesCard(feeData) + '<div class="eguru-suggestions"><button class="eguru-chip" onclick="eguruSendQuick(\'Make payment\')">💳 Pay Now</button><button class="eguru-chip" onclick="eguruSendQuick(\'Payment history\')">📜 History</button></div>';
        }

        if (lowerMsg.indexOf('course') !== -1 || lowerMsg.indexOf('subject') !== -1) {
            var courseData = {
                session: '<?php echo $current_session; ?>',
                semester: '<?php echo $current_semester; ?>',
                total_courses: 4,
                total_units: 8,
                courses: [
                    { course_code: 'EDT301', course_title: 'Secondary School Academic Bride Program', credit_units: 2, registration_status: 'Approved' },
                    { course_code: 'DIG301', course_title: 'Basic Computer Technology', credit_units: 2, registration_status: 'Approved' },
                    { course_code: 'DIG304', course_title: 'Artificial Intelligence', credit_units: 2, registration_status: 'Approved' }
                ]
            };
            return buildCoursesCard(courseData) + '<div class="eguru-suggestions"><button class="eguru-chip" onclick="eguruSendQuick(\'Timetable\')">📅 Timetable</button></div>';
        }

        if (lowerMsg.indexOf('profile') !== -1 || lowerMsg.indexOf('about me') !== -1) {
            var profileData = {
                name: '<?php echo $student_name; ?>',
                matric_number: '<?php echo $student_matric; ?>',
                email: '<?php echo $student_email; ?>',
                program: '<?php echo $student_program; ?>',
                department: '<?php echo $student_dept; ?>',
                level: '<?php echo $student_level; ?>',
                cgpa: '<?php echo $student_cgpa; ?>',
                status: '<?php echo $student_status; ?>'
            };
            return buildProfileCard(profileData) + '<div class="eguru-suggestions"><button class="eguru-chip" onclick="eguruSendQuick(\'Edit profile\')">✏️ Edit</button><button class="eguru-chip" onclick="eguruSendQuick(\'Change password\')">🔒 Password</button></div>';
        }

        if (lowerMsg.indexOf('help') !== -1 || lowerMsg.indexOf('command') !== -1) {
            return '<div>Here are the things I can help you with:</div>' +
            '<div class="eguru-info-card">' +
                '<h4>🤖 Available Commands</h4>' +
                '<p><span class="eguru-label">📊 Results</span> <span>View academic results & GPA</span></p>' +
                '<p><span class="eguru-label">💰 Fees</span> <span>Check balance & payments</span></p>' +
                '<p><span class="eguru-label">📚 Courses</span> <span>Registered courses info</span></p>' +
                '<p><span class="eguru-label">👤 Profile</span> <span>Student information</span></p>' +
                '<p><span class="eguru-label">🏠 Hostel</span> <span>Accommodation details</span></p>' +
                '<p><span class="eguru-label">📅 Session</span> <span>Academic calendar</span></p>' +
                '<p><span class="eguru-label">📄 Transcript</span> <span>Request transcripts</span></p>' +
                '<p><span class="eguru-label">🔔 Notifications</span> <span>Alerts & messages</span></p>' +
            '</div>' +
            '<div style="margin-top:8px;font-size:12px;color:var(--eguru-text-light)">Just type naturally, like "show my results" or "what fees do I owe?"</div>';
        }

        return '<div>I am not sure I understood that correctly. 🤔</div>' +
        '<div style="margin-top:8px">You can ask me about:</div>' +
        '<div class="eguru-suggestions">' +
            '<button class="eguru-chip" onclick="eguruSendQuick(\'My results\')">📊 Results</button>' +
            '<button class="eguru-chip" onclick="eguruSendQuick(\'My fees\')">💰 Fees</button>' +
            '<button class="eguru-chip" onclick="eguruSendQuick(\'My courses\')">📚 Courses</button>' +
            '<button class="eguru-chip" onclick="eguruSendQuick(\'Profile\')">👤 Profile</button>' +
            '<button class="eguru-chip" onclick="eguruSendQuick(\'Help\')">❓ Help</button>' +
        '</div>';
    }

    window.eguruClearChat = function() {
        chatMessages.innerHTML = 
            '<div class="eguru-welcome">' +
                '<div class="eguru-welcome-icon">' +
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">' +
                        '<path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/>' +
                        '<path d="M19 10v2a7 7 0 0 1-14 0v-2"/>' +
                        '<line x1="12" y1="19" x2="12" y2="22"/>' +
                        '<line x1="8" y1="22" x2="16" y2="22"/>' +
                    '</svg>' +
                '</div>' +
                '<h2>Conversation Cleared</h2>' +
                '<p>How can I help you today, <?php echo $student_first_name; ?>?</p>' +
            '</div>';
        messageHistory = [];
    };

    setTimeout(function() {
        if (!isOpen) {
            var unreadCount = <?php echo $chatbot_unread_notifs; ?>;
            if (unreadCount > 0) {
                var badge = document.querySelector('.eguru-toggle-badge');
                if (badge) badge.style.display = 'flex';
            }
        }
    }, 4000);

})(); 
</script>
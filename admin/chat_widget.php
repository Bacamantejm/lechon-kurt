<!-- Admin Floating Chat Widget -->
<!-- Include this file in your admin pages (e.g., in footer or sidebar) -->

<a href="chat.php" class="admin-floating-chat" title="Open Support Chat">
    <i class="fas fa-comments"></i>
    <span id="adminChatBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="display: none;">
        0
        <span class="visually-hidden">unread messages</span>
    </span>
</a>

<style>
.admin-floating-chat {
    position: fixed;
    bottom: 30px;
    right: 30px;
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #c62828 0%, #b71c1c 100%);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    z-index: 1050;
    text-decoration: none;
    transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
.admin-floating-chat:hover {
    transform: scale(1.1);
    color: white;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    function checkAdminMessages() {
        fetch('../api/get_conversations.php')
            .then(response => response.json())
            .then(data => {
                if (data.success && data.conversations) {
                    let totalUnread = data.conversations.reduce((sum, c) => sum + parseInt(c.unread_count || 0), 0);
                    const badge = document.getElementById('adminChatBadge');
                    if(badge) { badge.innerText = totalUnread; badge.style.display = totalUnread > 0 ? 'inline-block' : 'none'; }
                }
            }).catch(e => console.error(e));
    }
    setInterval(checkAdminMessages, 10000); // Check every 10 seconds
    checkAdminMessages();
});
</script>
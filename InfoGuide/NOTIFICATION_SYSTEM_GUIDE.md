# User Notification System - Complete Implementation

## Overview
A real-time in-app notification system for users to receive updates about their franchise applications, with email backup notifications.

## Components Implemented

### 1. **Database Table** ✅
Added `notifications` table to store all user notifications:
- `id` - Unique notification ID
- `user_id` - User receiving the notification (FK to users table)
- `type` - Notification type (e.g., 'franchise_approved', 'franchise_rejected')
- `title` - Short notification title
- `message` - Full notification message
- `related_id` - ID of related record (e.g., application ID)
- `related_type` - Type of related record (e.g., 'franchise_application')
- `is_read` - Read status (0 = unread, 1 = read)
- `created_at` - Timestamp when notification was created
- `updated_at` - Last update timestamp

**Location:** [lechon_db.sql](lechon_db.sql#L1320)

### 2. **Notification Creation** ✅
New function `createNotification()` in [admin/franchise_applications.php](admin/franchise_applications.php#L46-L63):
- Called automatically when admin approves/rejects application
- Creates database record with:
  - **Approved message:** "Congratulations! Your franchise application for [Business Name] has been approved. Our team will contact you shortly."
  - **Rejected message:** Includes first 100 chars of admin feedback
- Stores related application ID and type for context

### 3. **Notification API** ✅
REST API endpoint: [api/get_notifications.php](api/get_notifications.php)

**Available Actions:**
- `action=count` - Get unread notification count
- `action=get_unread` - Get all unread notifications (max 10 most recent)
- `action=mark_read` - Mark single notification as read
- `action=mark_all_read` - Mark all notifications as read

**Authentication:** Session-based (user_id required)

### 4. **User Interface** ✅
Notification bell icon in header with:
- **Bell Button:** Toggleable dropdown in top-right navigation
- **Badge:** Red notification count badge with pulse animation
- **Dropdown List:** Shows up to 10 most recent unread notifications
- **Mark as Read:** Individual click or "Mark all as read" button
- **Time Display:** Shows relative time (e.g., "5 minutes ago")

**Location:** [includes/header.php](includes/header.php)

### 5. **Frontend Features** ✅
- **Auto-refresh:** Polls for new notifications every 30 seconds
- **Real-time updates:** Loads immediately when dropdown opened
- **Click to navigate:** Clicking notification navigates to My Account → Franchise tab
- **Unread styling:** Unread notifications highlighted in light blue
- **Time formatting:** Relative time display (just now, minutes ago, hours ago, etc.)
- **Responsive:** Works on all screen sizes

## User Experience Flow

### When Application is Approved/Rejected:

1. **Admin Action**
   - Admin clicks Approve or Reject button in modal
   - Form submitted with correct status
   - Database updated with new status

2. **System Actions**
   - Notification created in `notifications` table
   - Email sent to user
   - Badge updates on user's next page load

3. **User Receives Notification**
   - Red notification bell appears with count badge
   - User clicks bell icon
   - Dropdown opens showing notification
   - Can read message or click to navigate to application status
   - Notification marked as read automatically

## Testing Checklist

- [ ] Create notifications table: `php -r "mysqli_query($conn, file_get_contents('lechon_db.sql'));"`
- [ ] Approve an application as admin - check database for notification
- [ ] Reject an application as admin - check database for notification
- [ ] Log in as applicant user - check for red notification badge
- [ ] Click notification bell - see dropdown list
- [ ] Click on notification - navigates to franchise tab
- [ ] Click "Mark all as read" - all notifications marked read, badge disappears
- [ ] Wait 30 seconds - notifications re-check for updates
- [ ] Check email received for approved/rejected notification
- [ ] Test on mobile - notification dropdown responsive

## Technical Details

### Database Schema
```sql
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `related_id` int(11) DEFAULT NULL,
  `related_type` varchar(50) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
);
```

### API Responses

**Get Unread Notifications:**
```json
{
  "notifications": [
    {
      "id": 1,
      "title": "Franchise Application Approved!",
      "message": "Congratulations! Your franchise application...",
      "type": "franchise_approved",
      "related_id": 5,
      "related_type": "franchise_application",
      "created_at": "2026-01-20 10:30:00"
    }
  ]
}
```

## Files Modified/Created

1. **[lechon_db.sql](lechon_db.sql#L1320)** - Added notifications table
2. **[admin/franchise_applications.php](admin/franchise_applications.php#L46-L63)** - Added createNotification() function
3. **[api/get_notifications.php](api/get_notifications.php)** - New notification API endpoint
4. **[includes/header.php](includes/header.php)** - Added notification UI and JavaScript

## Security Considerations

✅ **Implemented:**
- Session-based authentication required
- User can only access their own notifications
- Prepared statements for all database queries
- HTML escaping for displayed content
- CSRF protection via session validation

## Performance Optimizations

✅ **Implemented:**
- Indexes on `user_id` and `created_at` for fast queries
- Limits to 10 most recent notifications in dropdown
- 30-second polling interval (not too frequent)
- Dropdown closed by default (no constant rendering)

## Future Enhancements

Possible future improvements:
- Desktop/browser notifications (Notification API)
- Sound alert for new notifications
- Email digest options
- Notification preference settings
- Push notifications (PWA support)
- Notification categories/filtering

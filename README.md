# Gazette Notice Feed - Drupal 11 Module

A custom Drupal 11 module that fetches and displays notices from The Gazette REST API with pagination, semantic HTML5, and proper access controls.

## 🚀 Features

- **REST API Integration**: Fetches notices from The Gazette API
- **Pagination**: 10 results per page with Drupal pager navigation
- **Semantic HTML5**: Accessible markup with proper heading structure
- **Security**: Role-based access control with custom permissions
- **Modern Architecture**: Drupal 11 compatible with dependency injection
- **Error Handling**: Comprehensive error handling and logging

## 📋 Requirements

- Drupal 11.x
- PHP 8.1+
- Composer
- Guzzle HTTP Client (included with Drupal)

## 🛠️ Installation

### 1. Clone the Repository
```bash
git clone https://github.com/yourusername/gazette-notice-feed.git
cd gazette-notice-feed
```

### 2. Install Dependencies
```bash
composer install
```

### 3. Enable the Module
```bash
# Via Drush
drush en gazette_notice_feed

# Or via Drupal Admin UI
# Admin → Extend → Gazette Notice Feed → Enable
```

### 4. Configure Permissions
```bash
# Grant access to authenticated users
drush role:perm:add authenticated "access gazette notices"

```

### 5. Clear Cache
```bash
drush cr
```

## 🎯 Usage

### Access the Notice Feed
- **URL**: `/gazette-notices`
- **Access**: Requires "access gazette notices" permission
- **Pagination**: Navigate through pages using Drupal's standard pager

### API Endpoint
The module consumes data from:
```
https://www.thegazette.co.uk/all-notices/notice/data.json?results-page={page}
```

## 🔧 Configuration

### Permissions
- **`access gazette notices`**: View the Gazette notices feed
- **`administer gazette notices`**: Configure module settings (future feature)

### Roles with Access
- ✅ Authenticated users
- ✅ Content editors  
- ✅ Administrators
- ❌ Anonymous users (blocked for security)

## 🏗️ Architecture

### Module Structure
```
gazette_notice_feed/
├── gazette_notice_feed.info.yml          # Module metadata
├── gazette_notice_feed.routing.yml       # URL routing
├── gazette_notice_feed.services.yml      # Service definitions
├── gazette_notice_feed.permissions.yml   # Custom permissions
├── src/
│   ├── Controller/
│   │   └── GazetteNoticeFeedController.php  # Main controller
│   └── Service/
│       └── GazetteNoticeFeedApi.php         # API service
└── README.md
```

### Key Components

#### Controller (`GazetteNoticeFeedController`)
- Handles HTTP requests and responses
- Processes API data and renders HTML
- Manages pagination
- Implements dependency injection

#### Service (`GazetteNoticeFeedApi`)
- Makes HTTP requests to The Gazette API
- Handles error responses and logging
- Supports pagination via `results-page` parameter

## 🔒 Security Features

- **Custom Permissions**: Role-based access control
- **Input Sanitization**: HTML escaping for user data
- **SSL Verification**: Configurable for self-signed certificates
- **Error Logging**: Comprehensive logging without exposing sensitive data
- **Dependency Injection**: Secure service management

## 🧪 Testing

### Manual Testing
1. Visit `/gazette-notices` as an authenticated user
2. Test pagination by clicking "Next" and "Previous"
3. Verify semantic HTML structure
4. Check accessibility with screen readers

### API Testing
```bash
# Test API connectivity
curl "https://www.thegazette.co.uk/all-notices/notice/data.json?results-page=1"
```

## 🐛 Troubleshooting

### Common Issues

#### "No notices found" Message
- Check API connectivity
- Verify SSL certificate settings
- Review error logs: `drush watchdog:show --type=gazette_notice_feed`

#### Permission Denied (403)
- Ensure user has "access gazette notices" permission
- Check role assignments: `drush role:list`

#### Pagination Not Working
- Clear Drupal cache: `drush cr`
- Verify API returns `f:total` field
- Check for JavaScript errors in browser console

### Debug Mode
Enable detailed logging by checking Drupal's recent log messages:
```bash
drush watchdog:show --count=20
```


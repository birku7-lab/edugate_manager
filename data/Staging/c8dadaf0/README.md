# EduGate - School Attendance System

A modern, fast, and secure web-based attendance system using QR code scanning. Built with HTML/CSS/JS on the frontend and PHP/MySQL on the backend.

## 🚀 Setup Instructions

To set up this system on any new computer, follow these simple steps:

### 1. Prerequisites
- Download and install **XAMPP** (which includes Apache and MySQL).
- (Optional but recommended) Download **ngrok** if you want to make the system available online so that mobile phones can access the scanner.

### 2. Copy the Files
1. Open your XAMPP installation directory (usually `C:\xampp\htdocs\`).
2. Clone or copy this entire repository into a folder named `school attendance` inside the `htdocs` folder.
   - The path should be: `C:\xampp\htdocs\school attendance\`

### 3. Start the Servers
1. Open the **XAMPP Control Panel**.
2. Click **Start** next to **Apache**.
3. Click **Start** next to **MySQL**.

### 4. Initialize the Database
You do not need to manually create the database! The system has an auto-installer:
1. Open your web browser.
2. Go to: [http://localhost/school%20attendance/api/setup.php](http://localhost/school%20attendance/api/setup.php)
3. You should see a blank screen or a success message. This automatically creates the `school_attendance` database, creates all the tables, and generates the default Admin account.

### 5. Login
1. In your browser, navigate to: [http://localhost/school%20attendance/login.html](http://localhost/school%20attendance/login.html)
2. Log in using the default Admin credentials:
   - **Username:** `admin`
   - **Password:** `admin123`

*(Note: It is highly recommended to change this password in the Staff Management section after your first login!)*

### 6. Going Online (For Mobile Scanning)
If you want to use a smartphone to scan student QR codes:
1. Make sure ngrok is installed.
2. Double-click the `start_online.bat` script in the project folder.
3. It will generate a public link (e.g., `https://1a2b-3c4d.ngrok-free.app`). 
4. Open that link on your phone, log in, and navigate to the Scanner page!

---

## 🛠️ Features
- **Lightning Fast:** Fully asynchronous architecture that doesn't rely on page reloads.
- **Secure:** Role-Based Access Control (RBAC) ensures only Admins can access critical settings, while Staff can be restricted to specific pages.
- **QR Code Scanning:** Fast, built-in camera scanning using HTML5-QRCode. Includes Pause/Resume controls.
- **Reporting:** Real-time attendance logs and records viewing.

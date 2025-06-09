<?php
session_start();
require_once 'config/db.php'; // Database connection

// Handle contact form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['first_name'])) {
  header('Content-Type: application/json'); // Set JSON response header

  $errors = [];
  $response = ['success' => false, 'message' => '', 'errors' => []];

  // Sanitize and validate inputs
  $first_name = trim($_POST['first_name'] ?? '');
  $last_name = trim($_POST['last_name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $phone = trim($_POST['phone'] ?? '');
  $subject = trim($_POST['subject'] ?? '');
  $message = trim($_POST['message'] ?? '');

  // Validation rules
  if (empty($first_name)) {
    $errors['first_name'] = 'First name is required.';
  } elseif (strlen($first_name) > 50) {
    $errors['first_name'] = 'First name must not exceed 50 characters.';
  }

  if (empty($last_name)) {
    $errors['last_name'] = 'Last name is required.';
  } elseif (strlen($last_name) > 50) {
    $errors['last_name'] = 'Last name must not exceed 50 characters.';
  }

  if (empty($email)) {
    $errors['email'] = 'Email address is required.';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Invalid email format.';
  } elseif (strlen($email) > 100) {
    $errors['email'] = 'Email must not exceed 100 characters.';
  }

  if (!empty($phone) && !preg_match('/^[\d+\-\(\)\s]{0,20}$/', $phone)) {
    $errors['phone'] = 'Invalid phone number format.';
  }

  $valid_subjects = ['general', 'order', 'delivery', 'feedback', 'partnership', 'other'];
  if (empty($subject)) {
    $errors['subject'] = 'Subject is required.';
  } elseif (!in_array($subject, $valid_subjects)) {
    $errors['subject'] = 'Invalid subject selected.';
  }

  if (empty($message)) {
    $errors['message'] = 'Message is required.';
  } elseif (strlen($message) > 1000) {
    $errors['message'] = 'Message must not exceed 1000 characters.';
  }

  // If no errors, proceed to database insertion
  if (empty($errors)) {
    try {
      $query = "INSERT INTO contact_messages (first_name, last_name, email, phone, subject, message, created_at)
                      VALUES (?, ?, ?, ?, ?, ?, NOW())";
      $stmt = mysqli_prepare($conn, $query);
      if (!$stmt) {
        throw new Exception("Prepare failed: " . mysqli_error($conn));
      }

      // Bind parameters (phone is nullable, so use NULL if empty)
      $phone_param = !empty($phone) ? $phone : NULL;
      mysqli_stmt_bind_param($stmt, 'ssssss', $first_name, $last_name, $email, $phone_param, $subject, $message);

      if (!mysqli_stmt_execute($stmt)) {
        throw new Exception("Execute failed: " . mysqli_stmt_error($stmt));
      }

      $response = [
        'success' => true,
        'message' => 'Your message has been sent successfully. We will respond within 24 hours.'
      ];

      mysqli_stmt_close($stmt);
    } catch (Exception $e) {
      error_log("Contact form error: " . $e->getMessage(), 3, "logs/error_log");
      $response = [
        'success' => false,
        'message' => 'An error occurred while sending your message. Please try again later.'
      ];
    }
  } else {
    $response = [
      'success' => false,
      'message' => 'Please correct the errors in the form.',
      'errors' => $errors
    ];
  }

  echo json_encode($response);
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>FoodHub - Contact Us</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <style>
    :root {
      --primary-color: #ff6b35;
      --secondary-color: #f8f9fa;
      --dark-color: #2c3e50;
      --light-orange: #fff5f2;
      --success-color: #28a745;
      --danger-color: #dc3545;
      --warning-color: #ffc107;
      --info-color: #17a2b8;
      --light-gray: #f8f9fa;
      --medium-gray: #6c757d;
      --dark-gray: #495057;
      --white: #ffffff;
      --shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
      --light-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
      --border-radius: 15px;
      --transition: all 0.3s ease;
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      background-color: var(--secondary-color);
      color: var(--dark-color);
      line-height: 1.6;
    }

    /* Navigation */
    .navbar {
      background-color: var(--white);
      box-shadow: var(--light-shadow);
      padding: 1rem 0;
    }

    .navbar-brand {
      font-size: 1.8rem;
      font-weight: bold;
      color: var(--primary-color) !important;
    }

    .navbar-nav .nav-link {
      color: var(--dark-gray) !important;
      font-weight: 500;
      margin: 0 0.5rem;
      transition: var(--transition);
    }

    .navbar-nav .nav-link:hover {
      color: var(--primary-color) !important;
    }

    .navbar-nav .nav-link.active {
      color: var(--primary-color) !important;
    }

    /* Hero Section */
    .hero-section {
      background: linear-gradient(135deg, var(--primary-color), #ff8c42);
      color: var(--white);
      padding: 100px 0;
      position: relative;
      overflow: hidden;
    }

    .hero-section::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="20" cy="20" r="2" fill="rgba(255,255,255,0.1)"/><circle cx="80" cy="80" r="3" fill="rgba(255,255,255,0.1)"/><circle cx="40" cy="60" r="1.5" fill="rgba(255,255,255,0.1)"/></svg>');
      animation: float 20s infinite linear;
    }

    @keyframes float {
      0% {
        transform: rotate(0deg);
      }

      100% {
        transform: rotate(360deg);
      }
    }

    .hero-content {
      position: relative;
      z-index: 1;
    }

    .hero-title {
      font-size: 3.5rem;
      font-weight: bold;
      margin-bottom: 1rem;
    }

    .hero-subtitle {
      font-size: 1.3rem;
      opacity: 0.9;
    }

    /* Contact Section */
    .contact-section {
      padding: 80px 0;
    }

    .contact-card {
      background: var(--white);
      border-radius: var(--border-radius);
      box-shadow: var(--shadow);
      padding: 2.5rem;
      margin-bottom: 2rem;
      transition: var(--transition);
    }

    .contact-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
    }

    .contact-info-card {
      text-align: center;
      padding: 2rem;
    }

    .contact-icon {
      width: 80px;
      height: 80px;
      background: linear-gradient(135deg, var(--primary-color), #ff8c42);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 1.5rem;
      color: var(--white);
      font-size: 2rem;
    }

    .contact-info-title {
      font-size: 1.5rem;
      font-weight: bold;
      color: var(--dark-color);
      margin-bottom: 1rem;
    }

    .contact-info-text {
      color: var(--medium-gray);
      font-size: 1.1rem;
    }

    .contact-info-link {
      color: var(--primary-color);
      text-decoration: none;
      font-weight: 600;
      transition: var(--transition);
    }

    .contact-info-link:hover {
      color: #e55a2b;
      text-decoration: underline;
    }

    /* Contact Form */
    .form-section-title {
      font-size: 2.5rem;
      font-weight: bold;
      color: var(--dark-color);
      margin-bottom: 1rem;
      text-align: center;
    }

    .form-section-subtitle {
      color: var(--medium-gray);
      font-size: 1.1rem;
      margin-bottom: 3rem;
      text-align: center;
    }

    .form-group {
      margin-bottom: 1.5rem;
    }

    .form-label {
      color: var(--dark-gray);
      font-weight: 600;
      margin-bottom: 0.5rem;
    }

    .form-control {
      border: 2px solid var(--light-gray);
      border-radius: 10px;
      padding: 12px 16px;
      font-size: 1rem;
      transition: var(--transition);
      background-color: var(--light-gray);
    }

    .form-control:focus {
      border-color: var(--primary-color);
      box-shadow: 0 0 0 0.2rem rgba(255, 107, 53, 0.25);
      background-color: var(--white);
    }

    .form-select {
      border: 2px solid var(--light-gray);
      border-radius: 10px;
      padding: 12px 16px;
      font-size: 1rem;
      transition: var(--transition);
      background-color: var(--light-gray);
    }

    .form-select:focus {
      border-color: var(--primary-color);
      box-shadow: 0 0 0 0.2rem rgba(255, 107, 53, 0.25);
      background-color: var(--white);
    }

    .btn-primary {
      background: linear-gradient(135deg, var(--primary-color), #ff8c42);
      border: none;
      border-radius: 10px;
      padding: 12px 30px;
      font-weight: 600;
      font-size: 1.1rem;
      transition: var(--transition);
    }

    .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 5px 15px rgba(255, 107, 53, 0.4);
    }

    /* Map Section */
    .map-section {
      background-color: var(--white);
      padding: 80px 0;
    }

    .map-container {
      border-radius: var(--border-radius);
      overflow: hidden;
      box-shadow: var(--shadow);
      height: 400px;
      background: var(--light-gray);
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--medium-gray);
      font-size: 1.2rem;
    }

    /* FAQ Section */
    .faq-section {
      padding: 80px 0;
    }

    .faq-title {
      font-size: 2.5rem;
      font-weight: bold;
      color: var(--dark-color);
      margin-bottom: 3rem;
      text-align: center;
    }

    .accordion-item {
      border: none;
      margin-bottom: 1rem;
      border-radius: var(--border-radius) !important;
      overflow: hidden;
      box-shadow: var(--light-shadow);
    }

    .accordion-button {
      background-color: var(--white);
      border: none;
      font-weight: 600;
      color: var(--dark-color);
      padding: 1.25rem 1.5rem;
    }

    .accordion-button:not(.collapsed) {
      background-color: var(--light-orange);
      color: var(--primary-color);
      box-shadow: none;
    }

    .accordion-button:focus {
      box-shadow: 0 0 0 0.25rem rgba(255, 107, 53, 0.25);
    }

    .accordion-body {
      padding: 1.5rem;
      color: var(--medium-gray);
    }

    /* Footer */
    .footer {
      background-color: var(--dark-color);
      color: var(--white);
      padding: 40px 0 20px;
    }

    .footer-content {
      text-align: center;
    }

    .footer-brand {
      font-size: 1.5rem;
      font-weight: bold;
      color: var(--primary-color);
      margin-bottom: 1rem;
    }

    .footer-text {
      color: #bdc3c7;
      margin-bottom: 2rem;
    }

    .social-links {
      margin-bottom: 2rem;
    }

    .social-link {
      display: inline-block;
      width: 40px;
      height: 40px;
      background-color: var(--primary-color);
      color: var(--white);
      border-radius: 50%;
      text-align: center;
      line-height: 40px;
      margin: 0 0.5rem;
      transition: var(--transition);
    }

    .social-link:hover {
      background-color: #e55a2b;
      transform: translateY(-3px);
    }

    @media (max-width: 768px) {
      .hero-title {
        font-size: 2.5rem;
      }

      .hero-subtitle {
        font-size: 1.1rem;
      }

      .contact-card {
        padding: 1.5rem;
      }

      .form-section-title,
      .faq-title {
        font-size: 2rem;
      }
    }
  </style>
</head>

<body>
  <!-- Navigation -->
  <?php include 'includes/navbar.php'; ?>

  <!-- Hero Section -->
  <section class="hero-section">
    <div class="container">
      <div class="row justify-content-center">
        <div class="col-lg-8 text-center hero-content">
          <h1 class="hero-title">Get In Touch</h1>
          <p class="hero-subtitle">We'd love to hear from you. Send us a message and we'll respond as soon as possible.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- Contact Information -->
  <section class="contact-section">
    <div class="container">
      <div class="row">
        <div class="col-lg-4 col-md-6 mb-4">
          <div class="contact-card contact-info-card">
            <div class="contact-icon">
              <i class="fas fa-map-marker-alt"></i>
            </div>
            <h3 class="contact-info-title">Visit Us</h3>
            <p class="contact-info-text">
              123 Food Street<br>
              Downtown District<br>
              City, State 12345
            </p>
          </div>
        </div>
        <div class="col-lg-4 col-md-6 mb-4">
          <div class="contact-card contact-info-card">
            <div class="contact-icon">
              <i class="fas fa-phone"></i>
            </div>
            <h3 class="contact-info-title">Call Us</h3>
            <p class="contact-info-text">
              <a href="tel:+1234567890" class="contact-info-link">+1 (234) 567-8900</a><br>
              <a href="tel:+1234567891" class="contact-info-link">+1 (234) 567-8901</a><br>
              <small class="text-muted">Mon - Sun: 9:00 AM - 11:00 PM</small>
            </p>
          </div>
        </div>
        <div class="col-lg-4 col-md-6 mb-4">
          <div class="contact-card contact-info-card">
            <div class="contact-icon">
              <i class="fas fa-envelope"></i>
            </div>
            <h3 class="contact-info-title">Email Us</h3>
            <p class="contact-info-text">
              <a href="mailto:info@foodhub.com" class="contact-info-link">info@foodhub.com</a><br>
              <a href="mailto:support@foodhub.com" class="contact-info-link">support@foodhub.com</a><br>
              <small class="text-muted">We reply within 24 hours</small>
            </p>
          </div>
        </div>
      </div>

      <!-- Contact Form -->
      <div class="row justify-content-center mt-5">
        <div class="col-lg-8">
          <div class="contact-card">
            <h2 class="form-section-title">Send us a Message</h2>
            <p class="form-section-subtitle">Have a question or feedback? We'd love to hear from you.</p>

            <form id="contactForm">
              <div class="row">
                <div class="col-md-6">
                  <div class="form-group">
                    <label class="form-label">First Name *</label>
                    <input type="text" class="form-control" id="firstName" name="first_name" required>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="form-group">
                    <label class="form-label">Last Name *</label>
                    <input type="text" class="form-control" id="lastName" name="last_name" required>
                  </div>
                </div>
              </div>

              <div class="row">
                <div class="col-md-6">
                  <div class="form-group">
                    <label class="form-label">Email Address *</label>
                    <input type="email" class="form-control" id="email" name="email" required>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="form-group">
                    <label class="form-label">Phone Number</label>
                    <input type="tel" class="form-control" id="phone" name="phone">
                  </div>
                </div>
              </div>

              <div class="form-group">
                <label class="form-label">Subject *</label>
                <select class="form-select" id="subject" name="subject" required>
                  <option value="">Select a subject</option>
                  <option value="general">General Inquiry</option>
                  <option value="order">Order Issue</option>
                  <option value="delivery">Delivery Problem</option>
                  <option value="feedback">Feedback</option>
                  <option value="partnership">Partnership Opportunity</option>
                  <option value="other">Other</option>
                </select>
              </div>

              <div class="form-group">
                <label class="form-label">Message *</label>
                <textarea class="form-control" id="message" name="message" rows="6" placeholder="Please describe your inquiry in detail..." required></textarea>
              </div>

              <div class="text-center">
                <button type="submit" class="btn btn-primary">
                  <i class="fas fa-paper-plane me-2"></i>Send Message
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Map Section -->
  <section class="map-section">
    <div class="container">
      <div class="row">
        <div class="col-12">
          <div class="map-container">
            <div class="text-center">
              <i class="fas fa-map-marked-alt fa-3x mb-3"></i>
              <p>Interactive Map Would Be Embedded Here</p>
              <small class="text-muted">(Google Maps, OpenStreetMap, etc.)</small>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- FAQ Section -->
  <section class="faq-section">
    <div class="container">
      <h2 class="faq-title">Frequently Asked Questions</h2>
      <div class="row justify-content-center">
        <div class="col-lg-8">
          <div class="accordion" id="faqAccordion">
            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                  What are your delivery hours?
                </button>
              </h2>
              <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion">
                <div class="accordion-body">
                  We deliver 7 days a week from 9:00 AM to 11:00 PM. During peak hours (11:30 AM - 2:00 PM and 6:00 PM - 9:00 PM), delivery times may be slightly longer due to high demand.
                </div>
              </div>
            </div>

            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                  How much is the delivery fee?
                </button>
              </h2>
              <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                <div class="accordion-body">
                  Delivery fees vary by distance and typically range from $2.99 to $5.99. Orders over $30 qualify for free delivery within our standard delivery zone.
                </div>
              </div>
            </div>

            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                  Can I track my order?
                </button>
              </h2>
              <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                <div class="accordion-body">
                  Yes! Once your order is confirmed, you'll receive a tracking link via SMS and email. You can monitor your order status in real-time from preparation to delivery.
                </div>
              </div>
            </div>

            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                  What payment methods do you accept?
                </button>
              </h2>
              <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                <div class="accordion-body">
                  We accept all major credit cards (Visa, MasterCard, American Express), PayPal, Apple Pay, Google Pay, and cash on delivery for selected areas.
                </div>
              </div>
            </div>

            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq5">
                  How do I cancel or modify my order?
                </button>
              </h2>
              <div id="faq5" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                <div class="accordion-body">
                  Orders can be modified or cancelled within 5 minutes of placement. After this time, please contact our customer service immediately at +1 (234) 567-8900 for assistance.
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Footer -->
  <footer class="footer">
    <div class="container">
      <div class="footer-content">
        <h4 class="footer-brand"><i class="fas fa-utensils me-2"></i>FoodHub</h4>
        <p class="footer-text">Delicious food delivered to your doorstep</p>
        <div class="social-links">
          <a href="#" class="social-link"><i class="fab fa-facebook-f"></i></a>
          <a href="#" class="social-link"><i class="fab fa-twitter"></i></a>
          <a href="#" class="social-link"><i class="fab fa-instagram"></i></a>
          <a href="#" class="social-link"><i class="fab fa-linkedin-in"></i></a>
        </div>
        <p class="mb-0">© 2024 FoodHub. All rights reserved.</p>
      </div>
    </div>
  </footer>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
  <script>
    // Form submission handling
    document.getElementById('contactForm').addEventListener('submit', function(e) {
      e.preventDefault();

      const formData = new FormData(this);
      const formObject = {};
      formData.forEach((value, key) => {
        formObject[key] = value;
      });

      const requiredFields = ['first_name', 'last_name', 'email', 'subject', 'message'];
      let isValid = true;

      requiredFields.forEach(field => {
        const input = document.querySelector(`[name="${field}"]`);
        if (!input.value.trim()) {
          input.style.borderColor = 'var(--danger-color)';
          isValid = false;
        } else {
          input.style.borderColor = 'var(--light-gray)';
        }
      });

      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      const emailInput = document.getElementById('email');
      if (emailInput.value && !emailRegex.test(emailInput.value)) {
        emailInput.style.borderColor = 'var(--danger-color)';
        isValid = false;
      }

      if (isValid) {
        fetch('', { // Empty string since backend is in the same file
            method: 'POST',
            body: formData
          })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              alert(data.message);
              this.reset();
            } else {
              alert(data.message || 'Please fix the errors.');
              if (data.errors) {
                Object.keys(data.errors).forEach(field => {
                  const input = document.querySelector(`[name="${field}"]`);
                  if (input) input.style.borderColor = 'var(--danger-color)';
                });
              }
            }
          })
          .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again later.');
          });
      } else {
        alert('Please fill in all required fields correctly.');
      }
    });

    // Add interactive effects
    document.querySelectorAll('.form-control, .form-select').forEach(input => {
      input.addEventListener('focus', function() {
        this.style.transform = 'translateY(-1px)';
      });

      input.addEventListener('blur', function() {
        this.style.transform = 'translateY(0)';
      });
    });

    // Phone number formatting
    document.getElementById('phone').addEventListener('input', function() {
      this.value = this.value.replace(/[^\d+\-\(\)\s]/g, '');
    });

    // Smooth scrolling for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
      anchor.addEventListener('click', function(e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
          target.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
          });
        }
      });
    });
  </script>
</body>

</html>
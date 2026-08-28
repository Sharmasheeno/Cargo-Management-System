<?php
/**
 * index.php
 *faras cargo - Bogga Hore (Landing Page)
 * 
 * Boggan wuxuu soo bandhigayaa macluumaadka shirkadda,
 * isticmaalayaasha aan soo galin waxaa loo dirayaa boggan,
 * isticmaalayaasha soo galay waxaa loo dirayaa dashboard-kooda.
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Haddii uu isticmaale soo galay, u dir dashboard
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? 'staff';
    
    switch ($role) {
        case 'superadmin':
            header("Location: superadmin/dashboard.php");
            break;
        case 'company_admin':
            header("Location: tenant_admin/dashboard.php");
            break;
        case 'tenant_admin':
            header("Location: tenant_admin/dashboard.php");
            break;
        case 'staff':
            header("Location: staff/dashboard.php");
            break;
        case 'customer':
            header("Location: customer/dashboard.php");
            break;
        default:
            header("Location: login.php");
            break;
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="so">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cargo Management System | Xalka Caqliga ah ee Saadka</title>
     <link rel="icon" type="image/png" href="assets/images/curdun-favicon.png">

    <meta name="description" content="Cargo Management System - Shirkadda ugu kalsoonida badan Soomaaliya ee bixisa adeegyada saadka caalamiga ah, raadraaca alaabta, iyo maareynta ganacsiga.">
    <meta name="keywords" content="saad, cargo, Cargo Management System, somali ,cargo shipping, logistics">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --curdun-violet: #2D1859;
            --curdun-yellow: #F5C410;
            --curdun-violet-light: #4B2C85;
            --curdun-yellow-dark: #D4A70C;
            --curdun-white: #FFFFFF;
            --curdun-dark: #1B1233;
            --curdun-gray: #69647A;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            overflow-x: hidden;
        }

        img {
            max-width: 100%;
            height: auto;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--curdun-violet);
            min-height: 100vh;
        }
        
        /* Header Navigation */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 96px;
            padding: 0 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 1000;
            background: rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(10px);
            transition: background 0.3s ease, box-shadow 0.3s ease;
        }

        .navbar.scrolled {
            background: rgba(45, 24, 89, 0.95);
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.1);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: none;
        }

        .logo-icon {
            width: 200px;
            max-width: 40vw;
            padding: 5px 8px;
            background: white;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex: none;
        }

        .logo-img {
            display: block;
            width: 100%;
            height: auto;
            object-fit: contain;
        }

        .logo-text {
            font-size: 17px;
            font-weight: 700;
            color: white;
            padding-left: 12px;
            border-left: 1px solid rgba(255, 255, 255, 0.25);
            white-space: nowrap;
        }

        .logo-text span {
            color: var(--curdun-yellow);
        }
        
        .nav-links {
            display: flex;
            gap: 34px;
            align-items: center;
            flex: none;
        }
        
        .nav-links a {
            color: white;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }
        
        .nav-links a:hover {
            color: var(--curdun-yellow);
        }
        
        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
        }
        
        /* Main Container */
        .landing-container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 28px 60px;
        }

        /* Hero Section */
        .hero {
            display: grid;
            grid-template-columns: 1.05fr 0.95fr;
            align-items: center;
            gap: 50px;
            padding: 156px 0 60px;
        }

        .hero-content {
            animation: fadeInLeft 0.8s ease;
        }

        .hero-badge {
            background: rgba(245, 196, 16, 0.2);
            display: inline-block;
            padding: 6px 16px;
            border-radius: 30px;
            color: var(--curdun-yellow);
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 20px;
        }

        .hero-content h1 {
            font-size: clamp(38px, 4.6vw, 60px);
            font-weight: 800;
            color: white;
            margin-bottom: 20px;
            line-height: 1.1;
        }

        .hero-content h1 span {
            color: var(--curdun-yellow);
        }

        .hero-content p {
            font-size: 16px;
            max-width: 620px;
            color: rgba(255, 255, 255, 0.85);
            margin-bottom: 30px;
            line-height: 1.7;
        }

        .hero-buttons {
            display: inline-flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .btn-primary {
            background: var(--curdun-yellow);
            color: var(--curdun-violet);
            padding: 14px 32px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 700;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        
        .btn-primary:hover {
            background: white;
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }
        
        .btn-outline {
            border: 2px solid white;
            color: white;
            padding: 12px 30px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        
        .btn-outline:hover {
            background: white;
            color: var(--curdun-violet);
            transform: translateY(-3px);
        }
        
        .hero-image {
            animation: fadeInRight 0.8s ease;
            text-align: center;
        }

        .hero-image i {
            font-size: clamp(180px, 18vw, 240px);
            color: rgba(255, 255, 255, 0.22);
        }
        
        @keyframes fadeInLeft {
            from {
                opacity: 0;
                transform: translateX(-50px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        @keyframes fadeInRight {
            from {
                opacity: 0;
                transform: translateX(50px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        /* Section Title */
        .section-title {
            text-align: center;
            margin-bottom: 60px;
        }
        
        .section-title h2 {
            font-size: 36px;
            font-weight: 700;
            color: white;
            margin-bottom: 15px;
        }
        
        .section-title h2 span {
            color: var(--curdun-yellow);
        }
        
        .section-title p {
            color: rgba(255, 255, 255, 0.7);
            max-width: 600px;
            margin: 0 auto;
        }
        
        /* Features Grid */
        .features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-bottom: 100px;
        }
        
        .feature-card {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(10px);
            padding: 35px 25px;
            border-radius: 24px;
            transition: all 0.4s ease;
            border: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
        }
        
        .feature-card:hover {
            transform: translateY(-8px);
            background: rgba(255, 255, 255, 0.12);
            border-color: var(--curdun-yellow);
        }
        
        .feature-icon {
            width: 70px;
            height: 70px;
            background: rgba(245, 196, 16, 0.15);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            transition: all 0.3s ease;
        }
        
        .feature-card:hover .feature-icon {
            background: var(--curdun-yellow);
            border-radius: 50%;
        }
        
        .feature-icon i {
            font-size: 32px;
            color: var(--curdun-yellow);
            transition: all 0.3s ease;
        }
        
        .feature-card:hover .feature-icon i {
            color: var(--curdun-violet);
        }
        
        .feature-card h3 {
            color: white;
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 12px;
        }
        
        .feature-card p {
            color: rgba(255, 255, 255, 0.7);
            font-size: 14px;
            line-height: 1.6;
        }
        
        /* Stats Section */
        .stats {
            display: flex;
            justify-content: center;
            gap: 60px;
            flex-wrap: wrap;
            margin-bottom: 100px;
            padding: 40px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 30px;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-number {
            font-size: 42px;
            font-weight: 800;
            color: var(--curdun-yellow);
            display: block;
        }
        
        .stat-label {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.7);
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        /* How It Works */
        .how-it-works {
            margin-bottom: 100px;
        }
        
        .steps {
            display: flex;
            justify-content: space-between;
            gap: 30px;
            flex-wrap: wrap;
        }
        
        .step {
            flex: 1;
            text-align: center;
            position: relative;
        }
        
        .step-number {
            width: 60px;
            height: 60px;
            background: var(--curdun-yellow);
            color: var(--curdun-violet);
            font-size: 28px;
            font-weight: 800;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }
        
        .step h4 {
            color: white;
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .step p {
            color: rgba(255, 255, 255, 0.7);
            font-size: 14px;
        }
        
        /* CTA Section */
        .cta-section {
            background: linear-gradient(135deg, var(--curdun-yellow), var(--curdun-yellow-dark));
            border-radius: 30px;
            padding: 60px;
            text-align: center;
            margin-bottom: 60px;
        }
        
        .cta-section h2 {
            font-size: 32px;
            font-weight: 700;
            color: var(--curdun-violet);
            margin-bottom: 15px;
        }
        
        .cta-section p {
            color: var(--curdun-violet);
            margin-bottom: 25px;
            opacity: 0.9;
        }
        
        .btn-cta {
            background: var(--curdun-violet);
            color: white;
            padding: 14px 40px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
        }
        
        .btn-cta:hover {
            background: white;
            color: var(--curdun-violet);
            transform: translateY(-3px);
        }
        
        /* Footer */
        .footer {
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 40px;
            text-align: center;
        }
        
        .footer-content {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 30px;
            margin-bottom: 30px;
            text-align: left;
        }
        
        .footer-info {
            flex: 2;
        }
        
        .footer-info h3 {
            color: white;
            font-size: 18px;
            margin-bottom: 15px;
        }
        
        .footer-info p {
            color: rgba(255, 255, 255, 0.6);
            font-size: 13px;
            line-height: 1.6;
        }
        
        .footer-links {
            flex: 1;
        }
        
        .footer-links h4 {
            color: white;
            font-size: 14px;
            margin-bottom: 15px;
        }
        
        .footer-links a {
            display: block;
            color: rgba(255, 255, 255, 0.6);
            text-decoration: none;
            font-size: 13px;
            margin-bottom: 10px;
            transition: color 0.3s ease;
        }
        
        .footer-links a:hover {
            color: var(--curdun-yellow);
        }
        
        .social-icons {
            display: flex;
            gap: 15px;
            margin-top: 15px;
        }
        
        .social-icon {
            width: 38px;
            height: 38px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            text-decoration: none;
            color: white;
        }
        
        .social-icon:hover {
            background: var(--curdun-yellow);
            color: var(--curdun-violet);
            transform: translateY(-3px);
        }
        
        .copyright {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.4);
            font-size: 12px;
        }
        
        /* Responsive */
        /* Tablet */
        @media (max-width: 1024px) {
            .logo-icon {
                width: 170px;
            }

            .nav-links {
                gap: 24px;
            }

            .hero {
                grid-template-columns: 1fr;
                padding-top: 132px;
                text-align: center;
            }

            .hero-content p {
                margin-left: auto;
                margin-right: auto;
            }

            .hero-buttons {
                justify-content: center;
            }

            .hero-image {
                order: 2;
            }

            .hero-image i {
                font-size: clamp(140px, 30vw, 200px);
            }
        }

        /* Mobile */
        @media (max-width: 768px) {
            .navbar {
                height: 76px;
                padding: 0 20px;
            }

            .logo-icon {
                width: 150px;
                padding: 4px 8px;
            }

            .logo-text {
                font-size: 14px;
                padding-left: 8px;
            }

            .nav-links {
                display: none;
            }

            .mobile-menu-btn {
                display: block;
            }

            .landing-container {
                padding: 0 20px 40px;
            }

            .hero {
                padding-top: 108px;
                padding-bottom: 40px;
                gap: 36px;
            }

            .hero-content h1 {
                font-size: 32px;
            }

            .hero-image i {
                font-size: 150px;
            }

            .section-title h2 {
                font-size: 28px;
            }
            
            .stats {
                gap: 30px;
                padding: 30px;
            }
            
            .stat-number {
                font-size: 32px;
            }
            
            .cta-section {
                padding: 40px 25px;
            }
            
            .cta-section h2 {
                font-size: 24px;
            }
            
            .footer-content {
                flex-direction: column;
                text-align: center;
            }
            
            .social-icons {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar" id="navbar">
        <div class="logo">
            <div class="logo-icon">
    <img src="assets/images/curdun-logo1.png" alt="CURDUN ICT" class="logo-img">
</div>
            <div class="logo-text">
             Cargo   <span>Management System</span>
            </div>
        </div>
        <div class="nav-links">
            <a href="#home">Bogga Hore</a>
            <a href="#services">Adeegyada</a>
            <a href="#how-it-works">Sida Loo Isticmaalo</a>
            <a href="#contact">Xiriir</a>
        </div>
        <button class="mobile-menu-btn" id="mobileMenuBtn">
            <i class="fas fa-bars"></i>
        </button>
    </nav>

    <!-- Main Container -->
    <div class="landing-container">
        <!-- Hero Section -->
        <section class="hero" id="home">
            <div class="hero-content">
                <div class="hero-badge">
                    <i class="fas fa-star"></i> Ku Soo Dhawoow Cargo Management System
                </div>
                <h1>Xalka Caqliga ah ee <span>Saadka</span> iyo Maareynta Ganacsiga</h1>
                <p>Cargo Management System waxay kuu keenaysaa adeegyada saadka caalamiga ah, raadraaca alaabta waqtiga dhabta ah, iyo maareynta ganacsiga oo dhammaystiran.</p>
                <div class="hero-buttons">
                    <a href="login.php" class="btn-primary">
                        <i class="fas fa-sign-in-alt"></i> Soo Gal
                    </a>
                    <a href="#services" class="btn-outline">
                        <i class="fas fa-info-circle"></i> Faahfaahin Dheeri
                    </a>
                </div>
            </div>
            <div class="hero-image">
                <i class="fas fa-ship"></i>
            </div>
        </section>

        <!-- Features Section -->
        <section id="services">
            <div class="section-title">
                <h2>Adeegyadeena <span>Muhiimka Ah</span></h2>
                <p>Waxaan bixinaa adeegyo heer sare ah oo ku habboon baahiyaha ganacsigaaga</p>
            </div>
            
            <div class="features">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-globe-africa"></i>
                    </div>
                    <h3>Saad Caalami</h3>
                    <p>Waxaan u qaadnaa alaabta meel kasta oo adduunka ah, iyadoo la raacayo heerarka caalamiga ah.</p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-map-marker-alt"></i>
                    </div>
                    <h3>Raadraac alaabta</h3>
                    <p>Raadraac alaabta waqtiga dhabta ah, laga bilaabo goobta ay ka baxeen ilaa ay ka yimaadaan.</p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <h3>Maareynta Biilasha</h3>
                    <p>Samee biilasha si otomatik ah, raadraac bixinta, oo maarey dhaqaalaha ganacsigaaga.</p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <h3>Warbixinno Hufan</h3>
                    <p>Hel warbixinno faahfaahsan oo ku saabsan dakhliga, kharashyada, iyo waxqabadka ganacsiga.</p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <h3>Abaalmarin Loyalty</h3>
                    <p>Ku kasbo dhibcaha saad kasta oo aad samayso, una isticmaal qiimo dhimis iyo faa'iidooyin.</p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-headset"></i>
                    </div>
                    <h3>Taageero 24/7</h3>
                    <p>Koox taageero oo diyaar u ah inay ka caawiso caqabad kasta oo kula hor timaadda.</p>
                </div>
            </div>
        </section>

        <!-- Stats Section -->
        <div class="stats">
            <div class="stat-item">
                <span class="stat-number" id="statCustomers">0</span>
                <span class="stat-label">Macaamiisha Ku Qanacsan</span>
            </div>
            <div class="stat-item">
                <span class="stat-number" id="statContainers">0</span>
                <span class="stat-label">alaabta La Raray</span>
            </div>
            <div class="stat-item">
                <span class="stat-number" id="statCountries">0</span>
                <span class="stat-label">Dalal Loo Adeegay</span>
            </div>
            <div class="stat-item">
                <span class="stat-number" id="statExperience">0</span>
                <span class="stat-label">Sano Khibrad Ah</span>
            </div>
        </div>

        <!-- How It Works Section -->
        <section id="how-it-works" class="how-it-works">
            <div class="section-title">
                <h2>Sida Loo <span>Isticmaalo</span></h2>
                <p>Talooyin fudud oo ku saabsan sida aad u bilaabi karto adeegyadeena</p>
            </div>
            
            <div class="steps">
                <div class="step">
                    <div class="step-number">1</div>
                    <h4>Samee Akoon</h4>
                    <p>Iska diiwaan geli si aad u heshid akoon bilaash ah oo ku habboon.</p>
                </div>
                <div class="step">
                    <div class="step-number">2</div>
                    <h4>Ku Dar alaabta</h4>
                    <p>Ku dar macluumaadka alaabta aad rabto inaad raadraacdo.</p>
                </div>
                <div class="step">
                    <div class="step-number">3</div>
                    <h4>Raadraac & Maarey</h4>
                    <p>Raadraac alaabta waqtiga dhabta ah, maarey biilasha iyo bixinta.</p>
                </div>
            </div>
        </section>

        <!-- CTA Section -->
        <div class="cta-section">
            <h2>Diyaar Ma U Tahay Inaad Bilaabtid?</h2>
            <p>Ku soo biir Cargo Management System maanta oo bilow maareynta saadkaaga si caqli leh!</p>
            <a href="login.php" class="btn-cta">
                <i class="fas fa-arrow-right"></i> Hadda Soo Gal
            </a>
        </div>

        <!-- Footer -->
        <footer class="footer" id="contact">
            <div class="footer-content">
                <div class="footer-info">
                    <h3>Cargo Management System</h3>
                    <p>Cargo Management System waa shirkadda ugu kalsoonida badan Soomaaliya ee bixisa adeegyada saadka caalamiga ah, raadraaca alaabta, iyo maareynta ganacsiga.</p>
                    <div class="social-icons">
                        <a href="#" class="social-icon"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" class="social-icon"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="social-icon"><i class="fab fa-linkedin-in"></i></a>
                        <a href="#" class="social-icon"><i class="fab fa-instagram"></i></a>
                        <a href="#" class="social-icon"><i class="fab fa-whatsapp"></i></a>
                    </div>
                </div>
                <div class="footer-links">
                    <h4>Xiriirka Degdega ah</h4>
                    <a href="#home">Bogga Hore</a>
                    <a href="#services">Adeegyada</a>
                    <a href="#how-it-works">Sida Loo Isticmaalo</a>
                    <a href="login.php">Soo Gal</a>
                </div>
                <div class="footer-links">
                    <h4>Macluumaad</h4>
                    <a href="#">Nagu Saabsan</a>
                    <a href="#">Xiriir</a>
                    <a href="#">Shuruudaha Adeegga</a>
                    <a href="#">Qarsoodiga</a>
                </div>
                <div class="footer-links">
                    <h4>Xiriir Nagala Soo</h4>
                    <a href="mailto:info@curdun.com"><i class="fas fa-envelope"></i> info@curdun.com</a>
                    <a href="tel:+252610000000"><i class="fas fa-phone"></i> +252 61 000 0000</a>
                    <a href="#"><i class="fas fa-map-marker-alt"></i> Muqdisho, Soomaaliya</a>
                </div>
            </div>
            <div class="copyright">
                <p>&copy; <?= date('Y') ?> Cargo Management System. Dhammaan Xuquuqaha Way Dhawrsan Yihiin.</p>
                <p style="font-size:12px; opacity:0.6; margin-top:4px;">Built by <strong style="color:var(--curdun-yellow);">CURDUN ICT</strong></p>
            </div>
        </footer>
    </div>

    <script>
        // Navbar scroll effect
        window.addEventListener('scroll', function() {
            const navbar = document.getElementById('navbar');
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });
        
        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const navLinks = document.querySelector('.nav-links');
        
        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', function() {
                if (navLinks.style.display === 'flex') {
                    navLinks.style.display = 'none';
                } else {
                    navLinks.style.display = 'flex';
                    navLinks.style.flexDirection = 'column';
                    navLinks.style.position = 'absolute';
                    navLinks.style.top = '70px';
                    navLinks.style.left = '0';
                    navLinks.style.right = '0';
                    navLinks.style.background = 'rgba(45, 24, 89, 0.95)';
                    navLinks.style.padding = '20px';
                    navLinks.style.gap = '15px';
                }
            });
        }
        
        // Counter Animation
        function animateCounter(element, target, duration = 2000) {
            let start = 0;
            const increment = target / (duration / 16);
            const counter = setInterval(() => {
                start += increment;
                if (start >= target) {
                    element.textContent = target.toLocaleString();
                    clearInterval(counter);
                } else {
                    element.textContent = Math.floor(start).toLocaleString();
                }
            }, 16);
        }
        
        // Intersection Observer for counters
        const observerOptions = {
            threshold: 0.5,
            rootMargin: '0px'
        };
        
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const id = entry.target.id;
                    switch(id) {
                        case 'statCustomers':
                            animateCounter(entry.target, 5000);
                            break;
                        case 'statContainers':
                            animateCounter(entry.target, 25000);
                            break;
                        case 'statCountries':
                            animateCounter(entry.target, 45);
                            break;
                        case 'statExperience':
                            animateCounter(entry.target, 10);
                            break;
                    }
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);
        
        // Observe stat elements
        document.querySelectorAll('.stat-number').forEach(stat => {
            observer.observe(stat);
        });
        
        // Smooth scroll for anchor links
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
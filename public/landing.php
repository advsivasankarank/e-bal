<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>e-BAL – Financial Statement Preparation Platform</title>
<meta name="description" content="Generate Schedule III, LLP, Partnership, Proprietorship, Trust and Society Financial Statements directly from Tally Trial Balance.">
<link rel="canonical" href="https://ebal.etaxadv.com/">
<meta property="og:title" content="e-BAL – Financial Statement Preparation Platform">
<meta property="og:description" content="Generate Schedule III, LLP, Partnership, Proprietorship, Trust and Society Financial Statements directly from Tally Trial Balance.">
<meta property="og:url" content="https://ebal.etaxadv.com/">
<meta property="og:type" content="website">
<meta property="og:site_name" content="e-BAL">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth;-webkit-text-size-adjust:100%}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen,Ubuntu,Cantarell,sans-serif;color:#1e293b;background:#fafbfc;line-height:1.5;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}
ul{list-style:none}
.container{width:100%;max-width:1200px;margin:0 auto;padding:0 28px}
@media(max-width:480px){.container{padding:0 18px}}

/* ===== SVG ICONS ===== */
.icon-svg{width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0}

/* ===== TOP BAR ===== */
.topbar{background:#0b1e33;padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.06);position:sticky;top:0;z-index:100}
.topbar .container{display:flex;align-items:center;justify-content:space-between}
.topbar-brand{display:flex;align-items:center;gap:10px}
.topbar-logo{font-size:19px;font-weight:800;color:#fff;letter-spacing:-0.3px}
.topbar-logo span{color:#5eead4}
.topbar-tagline{font-size:11px;color:#94a3b8;font-weight:400}
@media(max-width:600px){.topbar-tagline{display:none}}
.topbar-links{display:flex;align-items:center;gap:14px}
.topbar-links a{font-size:13px;font-weight:500;color:#cbd5e1;transition:color .2s}
.topbar-links a:hover{color:#fff}
.topbar-links .btn-topbar{display:inline-block;padding:6px 16px;background:#0f766e;color:#fff;border-radius:6px;font-size:12px;font-weight:600;transition:background .2s}
.topbar-links .btn-topbar:hover{background:#115e59}
@media(max-width:480px){.topbar-links{gap:10px}.topbar-links a{font-size:12px}.topbar-links .btn-topbar{padding:5px 12px;font-size:11px}}

/* ===== BUTTONS ===== */
.btn-primary{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:13px 28px;background:#0f766e;color:#fff;border-radius:8px;font-size:14px;font-weight:600;border:none;cursor:pointer;transition:all .2s}
.btn-primary:hover{background:#115e59;box-shadow:0 6px 20px rgba(15,118,110,0.3)}
.btn-secondary{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:13px 28px;background:rgba(255,255,255,0.08);color:#e2e8f0;border-radius:8px;font-size:14px;font-weight:600;border:1px solid rgba(255,255,255,0.15);cursor:pointer;transition:all .2s}
.btn-secondary:hover{background:rgba(255,255,255,0.14);color:#fff}
.btn-outline{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:13px 28px;background:transparent;color:#0f766e;border-radius:8px;font-size:14px;font-weight:600;border:2px solid #0f766e;cursor:pointer;transition:all .2s}
.btn-outline:hover{background:#0f766e;color:#fff}
.btn-outline-light{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:13px 28px;background:transparent;color:#e2e8f0;border-radius:8px;font-size:14px;font-weight:600;border:2px solid rgba(255,255,255,0.2);cursor:pointer;transition:all .2s}
.btn-outline-light:hover{background:rgba(255,255,255,0.1)}

/* ===== SECTION COMMON ===== */
.section{padding:52px 0}
.section-alt{background:#fff}
.section-dark{background:#0b1e33}
.section-header{text-align:center;margin-bottom:36px}
.section-header h2{font-size:28px;font-weight:800;color:#0b1e33;letter-spacing:-0.4px;margin-bottom:8px}
.section-header p{font-size:15px;color:#64748b;max-width:600px;margin:0 auto}
.section-dark .section-header h2{color:#fff}
.section-dark .section-header p{color:#94a3b8}
@media(max-width:600px){.section{padding:36px 0}.section-header{margin-bottom:26px}.section-header h2{font-size:24px}}

/* ===== HERO ===== */
.hero{background:linear-gradient(135deg,#0b1e33 0,#112a45 50%,#163352 100%);padding:56px 0 60px;position:relative;overflow:hidden}
.hero .container{display:grid;grid-template-columns:1fr 1fr;gap:44px;align-items:center;position:relative;z-index:1}
.hero-content h1{font-size:44px;font-weight:800;color:#fff;letter-spacing:-1px;line-height:1.12;margin-bottom:6px}
.hero-content h1 span{color:#5eead4}
.hero-content .hero-sub{font-size:17px;font-weight:600;color:#5eead4;margin-bottom:14px}
.hero-content .hero-desc{font-size:15px;color:#94a3b8;line-height:1.6;margin-bottom:10px}
.hero-position{display:flex;flex-wrap:wrap;gap:8px 16px;margin-bottom:20px;padding:12px 16px;background:rgba(255,255,255,0.04);border-radius:8px;border:1px solid rgba(255,255,255,0.06)}
.hero-position-item{display:flex;align-items:center;gap:6px;font-size:13px;color:#cbd5e1}
.hero-position-item svg{width:16px;height:16px;flex-shrink:0}
.hero-actions{display:flex;gap:12px;flex-wrap:wrap}
.hero-visual{display:flex;align-items:center;justify-content:center}
.hero-card{width:100%;background:linear-gradient(145deg,#132a42,#1a3a58);border-radius:14px;border:1px solid rgba(255,255,255,0.08);box-shadow:0 20px 50px rgba(0,0,0,0.25);overflow:hidden}
.hero-card-header{display:flex;align-items:center;gap:10px;padding:14px 18px;background:rgba(0,0,0,0.15);border-bottom:1px solid rgba(255,255,255,0.06)}
.hero-card-dots{display:flex;gap:5px}
.hero-card-dots span{width:8px;height:8px;border-radius:50%;display:block}
.hero-card-dots span:nth-child(1){background:#ef4444}
.hero-card-dots span:nth-child(2){background:#f59e0b}
.hero-card-dots span:nth-child(3){background:#10b981}
.hero-card-title{font-size:11px;color:#64748b;font-weight:500}
.hero-card-body{padding:18px;display:grid;grid-template-columns:1fr 1fr;gap:8px}
.hero-card-stat{background:rgba(255,255,255,0.04);border-radius:8px;padding:14px;border:1px solid rgba(255,255,255,0.04)}
.hero-card-stat-label{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:3px}
.hero-card-stat-value{font-size:20px;font-weight:700;color:#fff}
.hero-card-stat-value.green{color:#5eead4}
.hero-card-stat-value.amber{color:#fbbf24}
.hero-card-stat-value.blue{color:#60a5fa}
.hero-card-bar{grid-column:1/-1;background:rgba(255,255,255,0.04);border-radius:8px;padding:12px 14px;display:flex;align-items:center;gap:12px;border:1px solid rgba(255,255,255,0.04)}
.hero-card-bar-track{flex:1;height:5px;background:rgba(255,255,255,0.08);border-radius:3px;overflow:hidden}
.hero-card-bar-fill{height:100%;width:72%;background:linear-gradient(90deg,#0f766e,#5eead4);border-radius:3px}
.hero-card-bar-label{font-size:11px;color:#64748b}
.hero-card-bar-pct{font-size:12px;font-weight:600;color:#5eead4}
@media(max-width:960px){.hero{padding:40px 0 44px}.hero .container{grid-template-columns:1fr;gap:28px}.hero-content h1{font-size:34px}.hero-content .hero-sub{font-size:15px}.hero-visual{max-width:480px;margin:0 auto}}
@media(max-width:480px){.hero-content h1{font-size:28px}.hero-content .hero-sub{font-size:14px}.hero-actions{flex-direction:column}.hero-actions .btn-primary,.hero-actions .btn-secondary{width:100%}.hero-card-body{grid-template-columns:1fr}}

/* ===== CREDIBILITY BAR ===== */
.cred-bar{background:#fff;border-bottom:1px solid #e2e8f0;padding:16px 0}
.cred-bar .container{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px 20px}
.cred-badges{display:flex;flex-wrap:wrap;gap:8px 18px}
.cred-badge{display:flex;align-items:center;gap:6px;font-size:13px;color:#475569;font-weight:500}
.cred-badge svg{width:16px;height:16px;flex-shrink:0}
.cred-developer{font-size:12px;color:#94a3b8}
.cred-developer strong{color:#0f766e;font-weight:600}
@media(max-width:640px){.cred-bar .container{justify-content:center;text-align:center}.cred-badges{justify-content:center}}

/* ===== REPORTING FRAMEWORK ===== */
.rf-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;max-width:860px;margin:0 auto}
.rf-item{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:18px 16px;display:flex;align-items:center;gap:12px;transition:box-shadow .2s}
.rf-item:hover{box-shadow:0 4px 16px rgba(0,0,0,0.04)}
.rf-item svg{width:20px;height:20px;flex-shrink:0}
.rf-item span{font-size:13px;font-weight:500;color:#1e293b}
@media(max-width:680px){.rf-grid{grid-template-columns:repeat(2,1fr);gap:10px}}
@media(max-width:400px){.rf-grid{grid-template-columns:1fr}}

/* ===== COMPARISON ===== */
.compare-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:780px;margin:0 auto}
.compare-col{border-radius:12px;padding:24px 22px}
.compare-col.traditional{background:#fef2f2;border:1px solid #fecaca}
.compare-col.ebal{background:#f0fdfa;border:1px solid #a7f3d0}
.compare-col h3{font-size:15px;font-weight:700;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid rgba(0,0,0,0.06);display:flex;align-items:center;gap:8px}
.compare-col h3 svg{width:18px;height:18px}
.compare-col.traditional h3{color:#dc2626}
.compare-col.ebal h3{color:#0f766e}
.compare-col ul{display:grid;gap:10px}
.compare-col li{font-size:13px;color:#475569;display:flex;align-items:center;gap:8px;line-height:1.4}
.compare-col li svg{width:15px;height:15px;flex-shrink:0}
@media(max-width:640px){.compare-grid{grid-template-columns:1fr;gap:14px}.compare-col{padding:20px 18px}}

/* ===== PRODUCT WORKSPACE ===== */
.workspace-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12px}
.workspace-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;transition:box-shadow .25s,transform .25s;cursor:default}
.workspace-card:hover{box-shadow:0 8px 24px rgba(0,0,0,0.06);transform:translateY(-2px)}
.workspace-card-visual{height:140px;background:linear-gradient(145deg,#0b1e33,#112a45);display:flex;align-items:center;justify-content:center;position:relative;border-bottom:1px solid rgba(255,255,255,0.06)}
.workspace-card-visual .ws-mock{display:grid;grid-template-columns:1fr 1fr;gap:4px;padding:12px;width:100%;height:100%}
.workspace-card-visual .ws-mock div{background:rgba(255,255,255,0.06);border-radius:3px}
.workspace-card-visual .ws-mock .wbar{grid-column:1/-1;height:4px;border-radius:2px;}
.workspace-card-visual .ws-mock .wbar.f1{width:65%;background:rgba(94,234,212,0.3)}
.workspace-card-visual .ws-mock .wbar.f2{width:45%;background:rgba(94,234,212,0.2)}
.workspace-card-caption{padding:12px 14px 14px}
.workspace-card-caption strong{display:block;font-size:12px;color:#0b1e33;margin-bottom:2px}
.workspace-card-caption span{font-size:11px;color:#64748b}
@media(max-width:900px){.workspace-grid{grid-template-columns:repeat(3,1fr);gap:10px}}
@media(max-width:540px){.workspace-grid{grid-template-columns:repeat(2,1fr);gap:10px}.workspace-card-visual{height:100px}}

/* ===== FEATURES ===== */
.features-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.feature-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px 22px;transition:box-shadow .2s,transform .2s}
.feature-card:hover{box-shadow:0 8px 24px rgba(0,0,0,0.04);transform:translateY(-1px)}
.feature-card svg{width:20px;height:20px;margin-bottom:12px}
.feature-card h3{font-size:15px;font-weight:700;color:#0b1e33;margin-bottom:6px}
.feature-card p{font-size:13px;color:#64748b;line-height:1.5;margin-bottom:10px}
.feature-tags{display:flex;flex-wrap:wrap;gap:5px}
.feature-tags span{background:#f1f5f9;color:#475569;font-size:10px;font-weight:500;padding:3px 8px;border-radius:4px}
@media(max-width:800px){.features-grid{grid-template-columns:repeat(2,1fr);gap:14px}}
@media(max-width:480px){.features-grid{grid-template-columns:1fr;gap:12px}.feature-card{padding:20px 18px}}

/* ===== HOW IT WORKS ===== */
.workflow{display:grid;grid-template-columns:repeat(5,1fr);gap:12px}
.workflow-step{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:22px 14px;text-align:center;transition:box-shadow .2s}
.workflow-step:hover{box-shadow:0 6px 20px rgba(0,0,0,0.04)}
.workflow-step svg{width:24px;height:24px;margin-bottom:10px}
.workflow-step strong{display:block;font-size:13px;color:#1e293b;margin-bottom:3px}
.workflow-step span{font-size:11px;color:#64748b;display:block;line-height:1.3}
@media(max-width:800px){.workflow{grid-template-columns:repeat(3,1fr);gap:10px}}
@media(max-width:480px){.workflow{grid-template-columns:1fr 1fr;gap:10px}.workflow-step{padding:18px 12px}}

/* ===== BRIDGE ===== */
.bridge-grid{display:grid;grid-template-columns:1fr 1fr;gap:36px;align-items:start}
.bridge-content p{font-size:14px;color:#475569;line-height:1.6;margin-bottom:16px}
.bridge-benefits{display:grid;gap:8px;margin-bottom:20px}
.bridge-benefits li{font-size:13px;color:#1e293b;display:flex;align-items:center;gap:8px}
.bridge-benefits li svg{width:16px;height:16px;flex-shrink:0}
.bridge-meta{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;text-align:center}
.bridge-meta-item span{display:block;font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;margin-bottom:2px}
.bridge-meta-item strong{font-size:13px;color:#0b1e33;font-weight:600}
.bridge-steps{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px 18px}
.bridge-steps h4{font-size:13px;font-weight:600;color:#0b1e33;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.bridge-steps h4 svg{width:16px;height:16px}
.bridge-steps ol{list-style:none;counter-reset:step;display:grid;gap:6px}
.bridge-steps ol li{counter-increment:step;font-size:13px;color:#475569;display:flex;align-items:center;gap:8px}
.bridge-steps ol li::before{content:counter(step);width:22px;height:22px;background:#0b1e33;color:#5eead4;border-radius:5px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0}
.bridge-visual{background:linear-gradient(135deg,#0b1e33,#112a45);border-radius:12px;padding:28px 22px;text-align:center;border:1px solid rgba(255,255,255,0.06)}
.bridge-visual svg{width:48px;height:48px;margin-bottom:12px}
.bridge-visual h3{font-size:16px;color:#fff;font-weight:700;margin-bottom:4px}
.bridge-visual p{font-size:12px;color:#94a3b8;max-width:240px;margin:0 auto}
@media(max-width:768px){.bridge-grid{grid-template-columns:1fr;gap:24px}.bridge-meta{padding:14px;gap:8px}}
@media(max-width:480px){.bridge-meta{grid-template-columns:1fr;gap:10px}}

/* ===== PRICING ===== */
.pricing-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;align-items:start}
.pricing-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:28px 24px;position:relative;transition:box-shadow .2s,transform .2s}
.pricing-card:hover{box-shadow:0 8px 28px rgba(0,0,0,0.04);transform:translateY(-1px)}
.pricing-card.featured{border-color:#0f766e;box-shadow:0 6px 24px rgba(15,118,110,0.1);transform:scale(1.02)}
.pricing-card.featured:hover{box-shadow:0 8px 32px rgba(15,118,110,0.15)}
.pricing-badge{position:absolute;top:-10px;left:50%;transform:translateX(-50%);background:#0f766e;color:#fff;font-size:10px;font-weight:700;padding:3px 14px;border-radius:999px;text-transform:uppercase;letter-spacing:0.5px}
.pricing-name{font-size:13px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px}
.pricing-price{font-size:34px;font-weight:800;color:#0b1e33;letter-spacing:-0.8px;margin-bottom:2px}
.pricing-price span{font-size:14px;font-weight:500;color:#64748b;letter-spacing:0}
.pricing-desc{font-size:12px;color:#64748b;margin-bottom:16px}
.pricing-divider{height:1px;background:#e2e8f0;margin-bottom:16px}
.pricing-features{display:grid;gap:10px;margin-bottom:20px}
.pricing-features li{font-size:13px;color:#475569;display:flex;align-items:center;gap:8px}
.pricing-features li svg{width:15px;height:15px;flex-shrink:0}
.pricing-btn{display:block;width:100%;padding:12px;border-radius:8px;font-size:13px;font-weight:600;text-align:center;border:none;cursor:pointer;transition:all .2s}
.pricing-btn.primary{background:#0f766e;color:#fff}
.pricing-btn.primary:hover{background:#115e59}
.pricing-btn.secondary{background:#f1f5f9;color:#1e293b}
.pricing-btn.secondary:hover{background:#e2e8f0}
.pricing-includes{text-align:center;margin-top:24px;padding:18px 24px;background:#fff;border-radius:12px;border:1px solid #e2e8f0}
.pricing-includes h4{font-size:14px;font-weight:600;color:#0b1e33;margin-bottom:12px}
.pricing-includes-grid{display:flex;flex-wrap:wrap;gap:8px 20px;justify-content:center}
.pricing-includes-grid span{font-size:12px;color:#475569;display:flex;align-items:center;gap:5px}
.pricing-includes-grid span svg{width:14px;height:14px;flex-shrink:0}
@media(max-width:860px){.pricing-grid{grid-template-columns:1fr;gap:14px;max-width:400px;margin:0 auto}.pricing-card.featured{transform:none}.pricing-card.featured:hover{transform:none}}
@media(max-width:480px){.pricing-card{padding:24px 20px}.pricing-price{font-size:30px}}

/* ===== CTA ===== */
.cta{background:linear-gradient(135deg,#0b1e33 0,#112a45 50%,#163352 100%);padding:48px 0;text-align:center}
.cta h2{font-size:28px;font-weight:800;color:#fff;letter-spacing:-0.4px;margin-bottom:10px}
.cta p{font-size:15px;color:#94a3b8;max-width:480px;margin:0 auto 24px;line-height:1.6}
.cta-actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
@media(max-width:480px){.cta{padding:36px 0}.cta h2{font-size:22px}.cta-actions{flex-direction:column;align-items:center}.cta-actions .btn-primary,.cta-actions .btn-secondary,.cta-actions .btn-outline-light{width:100%;max-width:300px}}

/* ===== CONTACT ===== */
.contact-grid{display:grid;grid-template-columns:1fr 1fr;gap:32px;align-items:center}
.contact-info h3{font-size:20px;font-weight:700;color:#0b1e33;margin-bottom:6px}
.contact-info p{font-size:14px;color:#64748b;margin-bottom:16px;line-height:1.5}
.contact-details{display:grid;gap:10px;margin-bottom:18px}
.contact-details li{font-size:13px;color:#1e293b;display:flex;align-items:center;gap:8px}
.contact-details li svg{width:18px;height:18px;flex-shrink:0}
.contact-visual{background:linear-gradient(135deg,#f0fdfa,#ccfbf1);border-radius:12px;padding:28px 22px;text-align:center;border:1px solid #a7f3d0}
.contact-visual svg{width:40px;height:40px;margin-bottom:10px}
.contact-visual h4{font-size:15px;color:#0f766e;font-weight:700;margin-bottom:4px}
.contact-visual p{font-size:12px;color:#475569;max-width:240px;margin:0 auto 14px}
@media(max-width:640px){.contact-grid{grid-template-columns:1fr;gap:20px}}

/* ===== STICKY CTA ===== */
.sticky-cta{position:fixed;bottom:0;left:0;right:0;background:#0b1e33;border-top:1px solid rgba(255,255,255,0.08);padding:10px 0;z-index:200;display:none}
.sticky-cta .container{display:flex;align-items:center;justify-content:center;gap:14px;flex-wrap:wrap}
.sticky-cta span{font-size:13px;color:#e2e8f0;font-weight:500;margin-right:8px}
.sticky-cta .btn-primary{padding:9px 20px;font-size:12px}
.sticky-cta .btn-outline-light{padding:9px 20px;font-size:12px}
@media(min-width:768px){.sticky-cta{display:block}}
body{padding-bottom:52px}
@media(max-width:480px){body{padding-bottom:0}.sticky-cta{display:none}}

/* ===== FOOTER ===== */
.footer{background:#071526;padding:40px 0 28px;border-top:1px solid rgba(255,255,255,0.04)}
.footer-grid{display:grid;grid-template-columns:2fr 1fr 1fr;gap:36px;margin-bottom:28px}
.footer-brand .footer-logo{font-size:20px;font-weight:800;color:#fff;margin-bottom:4px}
.footer-brand .footer-logo span{color:#5eead4}
.footer-brand p{font-size:12px;color:#64748b;line-height:1.5;max-width:280px}
.footer-col h4{font-size:12px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:12px}
.footer-col ul{display:grid;gap:8px}
.footer-col a{font-size:13px;color:#cbd5e1;transition:color .2s}
.footer-col a:hover{color:#5eead4}
.footer-bottom{border-top:1px solid rgba(255,255,255,0.04);padding-top:18px;text-align:center;font-size:11px;color:#475569}
@media(max-width:640px){.footer-grid{grid-template-columns:1fr;gap:24px}.footer-brand p{max-width:100%}}
</style>
</head>
<body>

<!-- ===== TOP BAR ===== -->
<header class="topbar">
  <div class="container">
    <div class="topbar-brand">
      <span class="topbar-logo">e<span>-BAL</span></span>
      <span class="topbar-tagline">Financial Statement Platform</span>
    </div>
    <div class="topbar-links">
      <a href="login.php">Login</a>
      <a href="https://etaxadv.com/contact">Contact</a>
      <a href="#bridge" class="btn-topbar">Download Bridge</a>
    </div>
  </div>
</header>

<!-- ===== HERO ===== -->
<section class="hero" id="hero">
  <div class="container">
    <div class="hero-content">
      <h1>Financial Statements<br><span>Ready in Minutes</span></h1>
      <p class="hero-sub">Trial Balance In &mdash; Financial Statements Out</p>
      <p class="hero-desc">e-BAL is not accounting software. It is a Financial Statement Preparation Platform. Import Trial Balance from Tally and generate professional financial statements instantly.</p>
      <div class="hero-position">
        <span class="hero-position-item">
          <svg viewBox="0 0 24 24" fill="none" stroke="#5eead4" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          Schedule III
        </span>
        <span class="hero-position-item">
          <svg viewBox="0 0 24 24" fill="none" stroke="#5eead4" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          LLP
        </span>
        <span class="hero-position-item">
          <svg viewBox="0 0 24 24" fill="none" stroke="#5eead4" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          Partnership
        </span>
        <span class="hero-position-item">
          <svg viewBox="0 0 24 24" fill="none" stroke="#5eead4" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          Proprietorship
        </span>
        <span class="hero-position-item">
          <svg viewBox="0 0 24 24" fill="none" stroke="#5eead4" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          Trust &amp; Society
        </span>
      </div>
      <div class="hero-actions">
        <a href="login.php" class="btn-primary">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
          Login to e-BAL
        </a>
        <a href="#bridge" class="btn-secondary">Download Smart Bridge</a>
      </div>
    </div>
    <div class="hero-visual">
      <div class="hero-card">
        <div class="hero-card-header">
          <div class="hero-card-dots"><span></span><span></span><span></span></div>
          <span class="hero-card-title">Statement Workspace &mdash; e-BAL</span>
        </div>
        <div class="hero-card-body">
          <div class="hero-card-stat">
            <div class="hero-card-stat-label">Companies</div>
            <div class="hero-card-stat-value green">12</div>
          </div>
          <div class="hero-card-stat">
            <div class="hero-card-stat-label">Statements</div>
            <div class="hero-card-stat-value blue">24</div>
          </div>
          <div class="hero-card-stat">
            <div class="hero-card-stat-label">Mapped</div>
            <div class="hero-card-stat-value green">98%</div>
          </div>
          <div class="hero-card-stat">
            <div class="hero-card-stat-label">Pending</div>
            <div class="hero-card-stat-value amber">3</div>
          </div>
          <div class="hero-card-bar">
            <span class="hero-card-bar-label">Overall Completion</span>
            <div class="hero-card-bar-track"><div class="hero-card-bar-fill"></div></div>
            <span class="hero-card-bar-pct">72%</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===== CREDIBILITY BAR ===== -->
<div class="cred-bar">
  <div class="container">
    <div class="cred-badges">
      <span class="cred-badge">
        <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Chartered Accountants
      </span>
      <span class="cred-badge">
        <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Tax Practitioners
      </span>
      <span class="cred-badge">
        <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Audit Firms
      </span>
      <span class="cred-badge">
        <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        MSME Consultants
      </span>
      <span class="cred-badge">
        <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Finance Professionals
      </span>
    </div>
    <span class="cred-developer">Developed by <strong>E Tax Advisors Private Limited</strong></span>
  </div>
</div>

<!-- ===== REPORTING FRAMEWORK ===== -->
<section class="section section-alt" id="reporting">
  <div class="container">
    <div class="section-header">
      <h2>Reporting Framework</h2>
      <p>Six regulation-ready financial statement types supported out of the box.</p>
    </div>
    <div class="rf-grid">
      <div class="rf-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
        <span>Companies Act Schedule III</span>
      </div>
      <div class="rf-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
        <span>LLP Financial Statements</span>
      </div>
      <div class="rf-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
        <span>Proprietorship Accounts</span>
      </div>
      <div class="rf-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
        <span>Partnership Accounts</span>
      </div>
      <div class="rf-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
        <span>Trust Financial Statements</span>
      </div>
      <div class="rf-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
        <span>Society Financial Statements</span>
      </div>
    </div>
  </div>
</section>

<!-- ===== TRADITIONAL vs e-BAL ===== -->
<section class="section" id="compare">
  <div class="container">
    <div class="section-header">
      <h2>Traditional Process vs e-BAL</h2>
      <p>See how e-BAL eliminates manual work at every stage.</p>
    </div>
    <div class="compare-grid">
      <div class="compare-col traditional">
        <h3>
          <svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          Traditional Process
        </h3>
        <ul>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Manual mapping</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Excel schedules</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Manual notes</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Repetitive work</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>High review effort</li>
        </ul>
      </div>
      <div class="compare-col ebal">
        <h3>
          <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          e-BAL
        </h3>
        <ul>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Automated mapping</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Structured schedules</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Auto-generated notes</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Comparative reporting</li>
          <li><svg viewBox="0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Faster finalisation</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<!-- ===== PRODUCT WORKSPACE ===== -->
<section class="section section-alt" id="workspace">
  <div class="container">
    <div class="section-header">
      <h2>Product Workspace</h2>
      <p>Five integrated modules that power the statement preparation pipeline.</p>
    </div>
    <div class="workspace-grid">
      <div class="workspace-card">
        <div class="workspace-card-visual">
          <div class="ws-mock">
            <div style="height:20px;grid-column:1/-1;background:rgba(255,255,255,0.06);border-radius:3px;margin-bottom:4px"></div>
            <div style="height:30px;background:rgba(94,234,212,0.15);border-radius:3px"></div>
            <div style="height:30px;background:rgba(94,234,212,0.08);border-radius:3px"></div>
            <div class="wbar f1"></div>
            <div class="wbar f2"></div>
          </div>
        </div>
        <div class="workspace-card-caption">
          <strong>TB Dashboard</strong>
          <span>Import &amp; validate trial balance</span>
        </div>
      </div>
      <div class="workspace-card">
        <div class="workspace-card-visual">
          <div class="ws-mock">
            <div style="height:8px;grid-column:1/-1;background:rgba(94,234,212,0.2);border-radius:3px;margin-bottom:4px"></div>
            <div style="height:30px;background:rgba(94,234,212,0.12);border-radius:3px"></div>
            <div style="height:30px;background:rgba(94,234,212,0.06);border-radius:3px"></div>
            <div style="height:10px;grid-column:1/-1;background:rgba(94,234,212,0.08);border-radius:3px"></div>
          </div>
        </div>
        <div class="workspace-card-caption">
          <strong>Mapping Workbench</strong>
          <span>AI-assisted ledger mapping</span>
        </div>
      </div>
      <div class="workspace-card">
        <div class="workspace-card-visual">
          <div class="ws-mock">
            <div style="height:16px;grid-column:1/-1;background:rgba(94,234,212,0.15);border-radius:3px;margin-bottom:4px"></div>
            <div style="height:40px;grid-column:1/-1;background:rgba(94,234,212,0.06);border-radius:3px"></div>
            <div style="height:20px;grid-column:1/-1;background:rgba(94,234,212,0.1);border-radius:3px"></div>
          </div>
        </div>
        <div class="workspace-card-caption">
          <strong>FS Workspace</strong>
          <span>Statement generation &amp; review</span>
        </div>
      </div>
      <div class="workspace-card">
        <div class="workspace-card-visual">
          <div class="ws-mock">
            <div style="height:6px;grid-column:1/-1;background:rgba(234,179,8,0.2);border-radius:3px;margin-bottom:4px"></div>
            <div style="height:30px;background:rgba(234,179,8,0.1);border-radius:3px"></div>
            <div style="height:30px;background:rgba(94,234,212,0.08);border-radius:3px"></div>
            <div style="height:6px;grid-column:1/-1;background:rgba(94,234,212,0.12);border-radius:3px"></div>
          </div>
        </div>
        <div class="workspace-card-caption">
          <strong>Validation Console</strong>
          <span>Error &amp; compliance checking</span>
        </div>
      </div>
      <div class="workspace-card">
        <div class="workspace-card-visual">
          <div class="ws-mock">
            <div style="height:20px;grid-column:1/-1;background:rgba(59,130,246,0.15);border-radius:3px;margin-bottom:4px"></div>
            <div style="height:12px;grid-column:1/-1;background:rgba(59,130,246,0.08);border-radius:3px;margin-bottom:4px"></div>
            <div style="height:30px;background:rgba(59,130,246,0.1);border-radius:3px"></div>
            <div style="height:30px;background:rgba(59,130,246,0.06);border-radius:3px"></div>
          </div>
        </div>
        <div class="workspace-card-caption">
          <strong>Export Centre</strong>
          <span>PDF, Excel &amp; Word exports</span>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===== KEY FEATURES ===== -->
<section class="section" id="features">
  <div class="container">
    <div class="section-header">
      <h2>Key Capabilities</h2>
      <p>Everything a CA firm needs for professional financial reporting.</p>
    </div>
    <div class="features-grid">
      <div class="feature-card">
        <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        <h3>Trial Balance Import</h3>
        <p>Import from Tally, XML, Excel, or CSV.</p>
        <div class="feature-tags">
          <span>Tally Prime</span>
          <span>Tally ERP 9</span>
          <span>XML</span>
          <span>Excel</span>
          <span>CSV</span>
        </div>
      </div>
      <div class="feature-card">
        <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 22 8.5 22 15.5 12 22 2 15.5 2 8.5 12 2"/><line x1="12" y1="22" x2="12" y2="15.5"/><polyline points="22 8.5 12 15.5 2 8.5"/></svg>
        <h3>Smart Ledger Mapping</h3>
        <p>AI-assisted classification with conflict detection.</p>
        <div class="feature-tags">
          <span>Auto-classify</span>
          <span>Smart suggestions</span>
          <span>Conflict detection</span>
        </div>
      </div>
      <div class="feature-card">
        <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
        <h3>Financial Statements</h3>
        <p>Schedule III, LLP, Partnership, Proprietorship.</p>
        <div class="feature-tags">
          <span>Corporate</span>
          <span>LLP</span>
          <span>Partnership</span>
          <span>Proprietorship</span>
        </div>
      </div>
      <div class="feature-card">
        <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 22 8.5 22 15.5 12 22 2 15.5 2 8.5"/><line x1="12" y1="22" x2="12" y2="15.5"/><polyline points="22 8.5 12 15.5 2 8.5"/></svg>
        <h3>Trust &amp; Society Reporting</h3>
        <p>Income &amp; Expenditure and Receipts &amp; Payments.</p>
        <div class="feature-tags">
          <span>Income &amp; Expenditure</span>
          <span>Receipts &amp; Payments</span>
        </div>
      </div>
      <div class="feature-card">
        <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        <h3>Comparative Statements</h3>
        <p>Current year vs previous year side-by-side.</p>
        <div class="feature-tags">
          <span>Current Year</span>
          <span>Previous Year</span>
        </div>
      </div>
      <div class="feature-card">
        <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        <h3>Professional Exports</h3>
        <p>Export to PDF, Excel, and Word formats.</p>
        <div class="feature-tags">
          <span>PDF</span>
          <span>Excel</span>
          <span>Word</span>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===== HOW IT WORKS ===== -->
<section class="section section-alt" id="how-it-works">
  <div class="container">
    <div class="section-header">
      <h2>How It Works</h2>
      <p>From trial balance to completed statements in five steps.</p>
    </div>
    <div class="workflow">
      <div class="workflow-step">
        <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><path d="M3 9h18"/></svg>
        <strong>Tally</strong>
        <span>Export from Tally Prime or ERP 9</span>
      </div>
      <div class="workflow-step">
        <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        <strong>Import TB</strong>
        <span>XML, Excel, or CSV import</span>
      </div>
      <div class="workflow-step">
        <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 22 8.5 22 15.5 12 22 2 15.5 2 8.5"/></svg>
        <strong>Map Ledgers</strong>
        <span>AI-assisted classification</span>
      </div>
      <div class="workflow-step">
        <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/></svg>
        <strong>Generate FS</strong>
        <span>Schedule III, LLP, and more</span>
      </div>
      <div class="workflow-step">
        <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/></svg>
        <strong>Export</strong>
        <span>PDF, Excel, or Word</span>
      </div>
    </div>
  </div>
</section>

<!-- ===== e-BAL SMART BRIDGE ===== -->
<section class="section" id="bridge">
  <div class="container">
    <div class="section-header">
      <h2>e-BAL Smart Bridge</h2>
      <p>Enterprise-grade connector for secure Tally-to-cloud data synchronisation.</p>
    </div>
    <div class="bridge-grid">
      <div class="bridge-content">
        <p>The Smart Bridge is a secure desktop agent that runs on your Windows environment and establishes a trusted channel between Tally and the e-BAL platform.</p>
        <ul class="bridge-benefits">
          <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            One-click synchronisation
          </li>
          <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            Tally Prime &amp; ERP 9 support
          </li>
          <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            XML integration
          </li>
          <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            Token-based secure authentication
          </li>
        </ul>
        <a href="tally_bridge_exe/release/eBAL_Smart_Bridge_Client_Release_2026-06-01/eBAL_Smart_Bridge_Client_2026-06-01.zip" class="btn-primary">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Download Smart Bridge
        </a>
        <div class="bridge-meta">
          <div class="bridge-meta-item">
            <span>Version</span>
            <strong>2.0.0</strong>
          </div>
          <div class="bridge-meta-item">
            <span>Release Date</span>
            <strong>June 2026</strong>
          </div>
          <div class="bridge-meta-item">
            <span>File Size</span>
            <strong>12.5 MB</strong>
          </div>
        </div>
        <div class="bridge-steps">
          <h4>
            <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            Installation
          </h4>
          <ol>
            <li>Download Smart Bridge</li>
            <li>Install on Windows</li>
            <li>Connect to Tally</li>
            <li>Sync to e-BAL</li>
          </ol>
        </div>
      </div>
      <div class="bridge-visual">
        <svg viewBox="0 0 24 24" fill="none" stroke="#5eead4" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
        <h3>Enterprise-Grade Connector</h3>
        <p>Secure, audited, and built for production accounting environments.</p>
        <div style="margin-top:14px;display:flex;justify-content:center;gap:10px;flex-wrap:wrap">
          <span style="display:flex;align-items:center;gap:5px;font-size:11px;color:#94a3b8"><svg viewBox="0 0 24 24" fill="none" stroke="#5eead4" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><polyline points="20 6 9 17 4 12"/></svg>TLS Encrypted</span>
          <span style="display:flex;align-items:center;gap:5px;font-size:11px;color:#94a3b8"><svg viewBox="0 0 24 24" fill="none" stroke="#5eead4" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><polyline points="20 6 9 17 4 12"/></svg>Audit Logged</span>
          <span style="display:flex;align-items:center;gap:5px;font-size:11px;color:#94a3b8"><svg viewBox="0 0 24 24" fill="none" stroke="#5eead4" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><polyline points="20 6 9 17 4 12"/></svg>Zero Config</span>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===== PRICING ===== -->
<section class="section section-alt" id="pricing">
  <div class="container">
    <div class="section-header">
      <h2>Simple &amp; Transparent Pricing</h2>
      <p>Choose the right plan for your practice. All plans include Smart Bridge.</p>
    </div>
    <div class="pricing-grid">
      <div class="pricing-card">
        <div class="pricing-name">Solo</div>
        <div class="pricing-price">&#8377;9,999 <span>/ Year</span></div>
        <p class="pricing-desc">For individual practitioners.</p>
        <div class="pricing-divider"></div>
        <ul class="pricing-features">
          <li><svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>1 User</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Up to 25 Companies</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>PDF &amp; Excel Export</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Smart Bridge</li>
        </ul>
        <a href="upgrade.php" class="pricing-btn secondary">Get Started</a>
      </div>
      <div class="pricing-card featured">
        <div class="pricing-badge">Most Popular</div>
        <div class="pricing-name">Firm</div>
        <div class="pricing-price">&#8377;24,999 <span>/ Year</span></div>
        <p class="pricing-desc">For growing CA firms.</p>
        <div class="pricing-divider"></div>
        <ul class="pricing-features">
          <li><svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>5 Users</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Up to 100 Companies</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>PDF, Excel &amp; Word Export</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Smart Bridge</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Priority Support</li>
        </ul>
        <a href="upgrade.php" class="pricing-btn primary">Get Started</a>
      </div>
      <div class="pricing-card">
        <div class="pricing-name">Enterprise</div>
        <div class="pricing-price">&#8377;49,999 <span>/ Year</span></div>
        <p class="pricing-desc">For established firms.</p>
        <div class="pricing-divider"></div>
        <ul class="pricing-features">
          <li><svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>15 Users</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Unlimited Companies</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Dedicated Support</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Multi-user Access</li>
        </ul>
        <a href="https://etaxadv.com/contact" class="pricing-btn secondary">Contact Sales</a>
      </div>
    </div>
    <div class="pricing-includes">
      <h4>All Plans Include</h4>
      <div class="pricing-includes-grid">
        <span><svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Smart Bridge</span>
        <span><svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Financial Statements</span>
        <span><svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Comparative Reports</span>
        <span><svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>PDF Export</span>
        <span><svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Excel Export</span>
        <span><svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Word Export</span>
      </div>
    </div>
  </div>
</section>

<!-- ===== CTA ===== -->
<section class="cta" id="cta">
  <div class="container">
    <h2>Ready to Prepare Financial Statements Faster?</h2>
    <p>Start using e-BAL today. No accounting software migration required.</p>
    <div class="cta-actions">
      <a href="login.php" class="btn-primary">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
        Login
      </a>
      <a href="#bridge" class="btn-secondary">Download Smart Bridge</a>
      <a href="https://etaxadv.com/contact" class="btn-outline-light">Contact Sales</a>
    </div>
  </div>
</section>

<!-- ===== CONTACT ===== -->
<section class="section section-alt" id="contact">
  <div class="container">
    <div class="section-header">
      <h2>Need a Demo?</h2>
      <p>Get a personalised walkthrough of e-BAL for your firm.</p>
    </div>
    <div class="contact-grid">
      <div class="contact-info">
        <h3>We're Here to Help</h3>
        <p>Schedule a demo or ask us anything about e-BAL.</p>
        <ul class="contact-details">
          <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
            <span>+91-XXXXXXXXXX</span>
          </li>
          <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <span>contact@etaxadv.com</span>
          </li>
        </ul>
        <a href="https://etaxadv.com/contact" class="btn-primary">Contact Sales</a>
      </div>
      <div class="contact-visual">
        <svg viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        <h4>Get a Personalised Demo</h4>
        <p>See how e-BAL can transform your financial statement preparation workflow.</p>
      </div>
    </div>
  </div>
</section>

<!-- ===== STICKY CTA ===== -->
<div class="sticky-cta">
  <div class="container">
    <span>Get started with e-BAL:</span>
    <a href="login.php" class="btn-primary">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
      Login
    </a>
    <a href="#bridge" class="btn-outline-light">Download Bridge</a>
    <a href="https://etaxadv.com/contact" class="btn-outline-light">Contact Sales</a>
  </div>
</div>

<!-- ===== FOOTER ===== -->
<footer class="footer">
  <div class="container">
    <div class="footer-grid">
      <div class="footer-brand">
        <div class="footer-logo">e<span>-BAL</span></div>
        <p>Financial Statement Preparation Platform by E Tax Advisors Private Limited.</p>
      </div>
      <div class="footer-col">
        <h4>Quick Links</h4>
        <ul>
          <li><a href="login.php">Login</a></li>
          <li><a href="#bridge">Download Smart Bridge</a></li>
          <li><a href="https://etaxadv.com/contact">Contact Us</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h4>Product</h4>
        <ul>
          <li><a href="#features">Features</a></li>
          <li><a href="#pricing">Pricing</a></li>
          <li><a href="#how-it-works">How It Works</a></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      &copy; 2026 e-BAL &mdash; A product by E Tax Advisors Private Limited. All rights reserved.
    </div>
  </div>
</footer>

</body>
</html>

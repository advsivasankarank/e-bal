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
/* ===== DESIGN TOKENS ===== */
:root{
  --navy:#12355B;--navy-light:#1E4D7B;
  --teal:#047857;--teal-light:#10B981;
  --gold:#C69214;--gold-light:#E6B84C;
  --bg:#F8FAFC;--text:#1E293B;
  --space-xs:clamp(0.25rem,0.4vw,0.5rem);
  --space-sm:clamp(0.5rem,0.8vw,0.75rem);
  --space-md:clamp(0.75rem,1.2vw,1.25rem);
  --space-lg:clamp(1rem,2vw,2rem);
  --space-xl:clamp(1.5rem,3vw,3rem);
  --space-2xl:clamp(2rem,4vw,4rem);
  --space-3xl:clamp(2.5rem,5vw,6rem);
  --header-height:44px;
  --sticky-cta-height:52px;
  --fs-hero-title:clamp(2.5rem,5vw,5rem);
  --fs-hero-sub:clamp(1rem,2vw,1.4rem);
  --fs-hero-desc:clamp(0.875rem,1.2vw,1rem);
  --fs-section-heading:clamp(1.625rem,3vw,3rem);
  --fs-section-sub:clamp(0.875rem,1.5vw,1.125rem);
}

/* ===== RESET ===== */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth;scroll-padding-top:var(--header-height);-webkit-text-size-adjust:100%}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen,Ubuntu,Cantarell,sans-serif;color:#1e293b;background:#f8fafc;line-height:1.5;padding-bottom:var(--sticky-cta-height);-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}
ul{list-style:none}

/* ===== CONTAINER ===== */
.container{width:100%;max-width:1600px;margin:0 auto;padding:0 clamp(16px,3vw,40px)}

/* ===== TOP BAR ===== */
.topbar{background:var(--navy);padding:var(--space-xs) 0;border-bottom:1px solid rgba(255,255,255,0.06);position:sticky;top:0;z-index:100}
.topbar .container{display:flex;align-items:center;justify-content:space-between}
.topbar-brand{display:flex;align-items:center;gap:var(--space-sm)}
.topbar-logo{font-size:clamp(16px,1.2vw,20px);font-weight:800;color:#fff;letter-spacing:-0.3px}
.topbar-logo span{color:var(--teal-light)}
.topbar-tagline{font-size:clamp(10px,0.8vw,12px);color:#94a3b8;font-weight:400}
@media(max-width:600px){.topbar-tagline{display:none}}
.topbar-links{display:flex;align-items:center;gap:var(--space-sm)}
.topbar-links a{font-size:clamp(11px,0.8vw,13px);font-weight:500;color:#cbd5e1;transition:color .2s}
.topbar-links a:hover{color:#fff}
.topbar-links .btn-topbar{display:inline-block;padding:var(--space-xs) var(--space-md);background:var(--teal);color:#fff;border-radius:5px;font-size:clamp(10px,0.7vw,12px);font-weight:600;transition:background .2s}
.topbar-links .btn-topbar:hover{background:#065F46}
@media(max-width:480px){.topbar-links{gap:6px}.topbar-links a{font-size:11px}.topbar-links .btn-topbar{padding:4px 10px;font-size:10px}}

/* ===== POSITIONING BADGE ===== */
.positioning-badge{display:inline-flex;align-items:center;gap:5px;background:rgba(16,185,129,0.12);color:var(--teal-light);font-size:clamp(9px,0.7vw,11px);font-weight:700;padding:var(--space-xs) var(--space-md);border-radius:999px;text-transform:uppercase;letter-spacing:0.6px;margin-bottom:var(--space-sm);border:1px solid rgba(16,185,129,0.15)}

/* ===== COMPLIANCE STRIP ===== */
.compliance-strip{background:var(--navy);padding:var(--space-xs) 0;border-bottom:1px solid rgba(255,255,255,0.05)}
.compliance-strip .container{display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:4px var(--space-lg)}
.compliance-item{display:flex;align-items:center;gap:4px;font-size:clamp(9px,0.7vw,11px);color:#94a3b8;font-weight:500}
.compliance-item svg{width:12px;height:12px;flex-shrink:0}

/* ===== SAMPLE OUTPUT ===== */
.sample-output{background:#fff;border-radius:8px;padding:14px;font-family:'Courier New',monospace;font-size:10px;line-height:1.5;color:#1e293b;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,0.06)}
.sample-output .so-header{font-size:10px;font-weight:700;color:var(--navy);text-align:center;padding-bottom:6px;margin-bottom:6px;border-bottom:1px solid #e2e8f0}
.sample-output .so-row{display:flex;justify-content:space-between;padding:1px 0}
.sample-output .so-row .so-label{color:#475569}
.sample-output .so-row .so-value{font-weight:600;color:var(--navy)}
.sample-output .so-row .so-value.neg{color:#dc2626}
.sample-output .so-total{border-top:1px solid #1e293b;margin-top:3px;padding-top:3px;font-weight:700}
.sample-output .so-section-label{font-weight:600;color:var(--teal);font-size:9px;margin:4px 0 2px}
.sample-output-sm{font-size:9px;padding:10px}
.sample-output-sm .so-header{font-size:9px}
.sample-output-sm .so-row{font-size:8px}

/* ===== BUTTONS ===== */
.btn-primary{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:var(--space-sm) var(--space-lg);background:var(--teal);color:#fff;border-radius:7px;font-size:clamp(12px,0.9vw,14px);font-weight:600;border:none;cursor:pointer;transition:all .2s}
.btn-primary:hover{background:#065F46;box-shadow:0 4px 16px rgba(4,120,87,0.3),0 0 20px rgba(16,185,129,0.15)}
.btn-secondary{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:var(--space-sm) var(--space-lg);background:rgba(255,255,255,0.08);color:#e2e8f0;border-radius:7px;font-size:clamp(12px,0.9vw,14px);font-weight:600;border:1px solid rgba(255,255,255,0.15);cursor:pointer;transition:all .2s}
.btn-secondary:hover{background:rgba(255,255,255,0.14);color:#fff}
.btn-outline{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:var(--space-sm) var(--space-lg);background:transparent;color:var(--teal);border-radius:7px;font-size:clamp(12px,0.9vw,14px);font-weight:600;border:2px solid var(--teal);cursor:pointer;transition:all .2s}
.btn-outline:hover{background:var(--teal);color:#fff}
.btn-outline-light{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:var(--space-sm) var(--space-lg);background:transparent;color:#e2e8f0;border-radius:7px;font-size:clamp(12px,0.9vw,14px);font-weight:600;border:2px solid rgba(255,255,255,0.2);cursor:pointer;transition:all .2s}
.btn-outline-light:hover{background:rgba(255,255,255,0.1)}
.btn-orange{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:var(--space-sm) var(--space-lg);background:var(--gold);color:#fff;border-radius:7px;font-size:clamp(12px,0.9vw,14px);font-weight:600;border:none;cursor:pointer;transition:all .2s}
.btn-orange:hover{background:#A67A0F;box-shadow:0 4px 16px rgba(198,146,20,0.3)}

/* ===== SECTION COMMON ===== */
.section{padding:var(--space-2xl) 0}
.section-alt{background:#fff}
.section-dark{background:var(--navy)}
.section-header{text-align:center;margin-bottom:var(--space-xl)}
.section-header h2{font-size:var(--fs-section-heading);font-weight:800;color:var(--navy);letter-spacing:-0.4px;margin-bottom:var(--space-xs)}
.section-header p{font-size:var(--fs-section-sub);color:#64748b;max-width:600px;margin:0 auto}
.section-dark .section-header h2{color:#fff}
.section-dark .section-header p{color:#94a3b8}
@media(max-width:600px){.section{padding:var(--space-xl) 0}.section-header{margin-bottom:var(--space-lg)}}

/* ===== HERO ===== */
.hero{background:linear-gradient(135deg,var(--navy) 0,#163A5F 50%,var(--navy-light) 100%);min-height:100vh;display:flex;align-items:center;position:relative;overflow:hidden}
.hero .container{display:grid;grid-template-columns:1fr 1fr;gap:clamp(24px,4vw,64px);align-items:center;width:100%;padding-top:var(--header-height);padding-bottom:var(--space-lg)}
.hero-content h1{font-size:var(--fs-hero-title);font-weight:800;color:#fff;letter-spacing:-0.9px;line-height:1.1;margin-bottom:var(--space-sm)}
.hero-content h1 span{color:var(--teal-light)}
.hero-content .hero-sub{font-size:var(--fs-hero-sub);font-weight:600;color:var(--teal-light);margin-bottom:var(--space-md)}
.hero-content .hero-desc{font-size:var(--fs-hero-desc);color:#94a3b8;line-height:1.5;margin-bottom:var(--space-sm)}
.hero-position{display:flex;flex-wrap:wrap;gap:6px var(--space-lg);margin-bottom:var(--space-md);padding:var(--space-sm) var(--space-md);background:rgba(255,255,255,0.04);border-radius:7px;border:1px solid rgba(255,255,255,0.06)}
.hero-position-item{display:flex;align-items:center;gap:5px;font-size:clamp(11px,0.8vw,13px);color:#cbd5e1}
.hero-position-item svg{width:14px;height:14px;flex-shrink:0}
.hero-kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:var(--space-sm);margin-bottom:var(--space-lg)}
.hero-kpi{background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.06);border-radius:8px;padding:var(--space-md);text-align:center}
.hero-kpi-value{font-size:clamp(16px,1.4vw,22px);font-weight:700;color:#fff;margin-bottom:1px}
.hero-kpi-label{font-size:clamp(9px,0.7vw,11px);color:#94a3b8;text-transform:uppercase;letter-spacing:0.3px}
.hero-actions{display:flex;gap:var(--space-sm);flex-wrap:wrap}
.hero-visual{display:flex;flex-direction:column;gap:var(--space-sm)}
.hero-screens{display:grid;grid-template-columns:1fr 1fr;gap:var(--space-sm)}
.hero-screen-main{grid-column:1/-1;background:linear-gradient(145deg,#132a42,#1a3a58);border-radius:10px;border:1px solid rgba(255,255,255,0.08);overflow:hidden;box-shadow:0 12px 36px rgba(0,0,0,0.2)}
.hero-screen-sm{background:linear-gradient(145deg,#132a42,#1a3a58);border-radius:8px;border:1px solid rgba(255,255,255,0.06);overflow:hidden;box-shadow:0 8px 24px rgba(0,0,0,0.15)}
.hero-screen-header{display:flex;align-items:center;gap:8px;padding:var(--space-xs) var(--space-md);background:rgba(0,0,0,0.15);border-bottom:1px solid rgba(255,255,255,0.05)}
.hero-screen-dots{display:flex;gap:4px}
.hero-screen-dots span{width:6px;height:6px;border-radius:50%;display:block}
.hero-screen-dots span:nth-child(1){background:#ef4444}
.hero-screen-dots span:nth-child(2){background:var(--gold)}
.hero-screen-dots span:nth-child(3){background:#10b981}
.hero-screen-title{font-size:clamp(8px,0.6vw,10px);color:#64748b;font-weight:500}
.hero-screen-body{padding:var(--space-sm) var(--space-md)}
@media(max-width:960px){
  .hero{min-height:auto;display:block;padding-top:calc(var(--header-height) + var(--space-lg));padding-bottom:var(--space-lg)}
  .hero .container{display:grid;grid-template-columns:1fr;gap:var(--space-lg);align-items:start}
  .hero-visual{max-width:520px;margin:0 auto;width:100%}
}
@media(max-width:480px){
  .hero-actions{flex-direction:column}
  .hero-actions .btn-primary,.hero-actions .btn-secondary{width:100%}
  .hero-kpis{grid-template-columns:1fr 1fr}
  .hero-screens{grid-template-columns:1fr}
}

/* ===== CREDIBILITY BAR ===== */
.cred-bar{background:#fff;border-bottom:1px solid #e2e8f0;padding:var(--space-sm) 0}
.cred-bar .container{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:var(--space-xs) var(--space-lg)}
.cred-badges{display:flex;flex-wrap:wrap;gap:var(--space-xs) var(--space-lg)}
.cred-badge{display:flex;align-items:center;gap:5px;font-size:clamp(11px,0.8vw,13px);color:#475569;font-weight:500}
.cred-badge svg{width:14px;height:14px;flex-shrink:0}
.cred-developer{font-size:clamp(10px,0.7vw,12px);color:#94a3b8}
.cred-developer strong{color:var(--teal);font-weight:600}
@media(max-width:640px){.cred-bar .container{justify-content:center;text-align:center}.cred-badges{justify-content:center}}

/* ===== INSIDE e-BAL (PRODUCT SCREENSHOTS) ===== */
.inside-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:var(--space-sm)}
.inside-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;transition:box-shadow .25s,transform .25s;cursor:default}
.inside-card:hover{box-shadow:0 6px 20px rgba(0,0,0,0.06);transform:translateY(-2px)}
.inside-card-visual{min-height:clamp(80px,12vw,130px);background:linear-gradient(145deg,var(--navy),#163A5F);display:flex;align-items:center;justify-content:center;position:relative;border-bottom:1px solid rgba(255,255,255,0.06)}
.inside-card-caption{padding:var(--space-sm) var(--space-md) var(--space-md)}
.inside-card-caption strong{display:block;font-size:clamp(10px,0.8vw,12px);color:var(--navy);margin-bottom:1px}
.inside-card-caption span{font-size:clamp(9px,0.7vw,11px);color:#64748b}
@media(max-width:900px){.inside-grid{grid-template-columns:repeat(3,1fr);gap:var(--space-sm)}}
@media(max-width:540px){.inside-grid{grid-template-columns:repeat(2,1fr);gap:var(--space-sm)}.inside-card-visual{min-height:clamp(60px,18vw,100px)}}

/* ===== REPORTING FRAMEWORK ===== */
.rf-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:var(--space-sm);max-width:840px;margin:0 auto}
.rf-item{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:var(--space-md);display:flex;align-items:center;gap:var(--space-sm);transition:box-shadow .2s}
.rf-item:hover{box-shadow:0 3px 12px rgba(0,0,0,0.04)}
.rf-item svg{width:18px;height:18px;flex-shrink:0}
.rf-item span{font-size:clamp(11px,0.8vw,13px);font-weight:500;color:#1e293b}
@media(max-width:680px){.rf-grid{grid-template-columns:repeat(2,1fr);gap:var(--space-sm)}}
@media(max-width:400px){.rf-grid{grid-template-columns:1fr}}

/* ===== COMPARISON ===== */
.compare-grid{display:grid;grid-template-columns:1fr 1fr;gap:var(--space-lg);max-width:760px;margin:0 auto}
.compare-col{border-radius:10px;padding:var(--space-lg)}
.compare-col.traditional{background:#fef2f2;border:1px solid #fecaca}
.compare-col.ebal{background:#f0fdfa;border:1px solid #a7f3d0}
.compare-col h3{font-size:clamp(13px,1vw,15px);font-weight:700;margin-bottom:var(--space-md);padding-bottom:var(--space-sm);border-bottom:1px solid rgba(0,0,0,0.06);display:flex;align-items:center;gap:7px}
.compare-col h3 svg{width:16px;height:16px}
.compare-col.traditional h3{color:#dc2626}
.compare-col.ebal h3{color:var(--teal)}
.compare-col ul{display:grid;gap:var(--space-sm)}
.compare-col li{font-size:clamp(11px,0.8vw,13px);color:#475569;display:flex;align-items:center;gap:7px;line-height:1.4}
.compare-col li svg{width:14px;height:14px;flex-shrink:0}
.compare-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:var(--space-sm);max-width:640px;margin:var(--space-lg) auto 0}
.compare-metric{text-align:center;padding:var(--space-md) var(--space-sm);background:#fff;border:1px solid #e2e8f0;border-radius:8px}
.compare-metric-value{font-size:clamp(18px,1.6vw,24px);font-weight:800;color:var(--teal);margin-bottom:1px}
.compare-metric-label{font-size:clamp(10px,0.8vw,12px);color:#64748b}
@media(max-width:640px){.compare-grid{grid-template-columns:1fr;gap:var(--space-md)}.compare-col{padding:var(--space-md)}.compare-metrics{grid-template-columns:1fr 1fr;gap:var(--space-sm)}}

/* ===== FEATURES ===== */
.features-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:var(--space-md)}
.feature-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:var(--space-lg);transition:box-shadow .2s,transform .2s}
.feature-card:hover{box-shadow:0 6px 18px rgba(0,0,0,0.04);transform:translateY(-1px)}
.feature-card svg{width:18px;height:18px;margin-bottom:var(--space-sm)}
.feature-card h3{font-size:clamp(13px,1vw,15px);font-weight:700;color:var(--navy);margin-bottom:var(--space-xs)}
.feature-card p{font-size:clamp(11px,0.8vw,13px);color:#64748b;line-height:1.5;margin-bottom:var(--space-sm)}
.feature-tags{display:flex;flex-wrap:wrap;gap:4px}
.feature-tags span{background:#f1f5f9;color:#475569;font-size:9px;font-weight:500;padding:2px 7px;border-radius:3px}
@media(max-width:800px){.features-grid{grid-template-columns:repeat(2,1fr);gap:var(--space-md)}}
@media(max-width:480px){.features-grid{grid-template-columns:1fr;gap:var(--space-sm)}.feature-card{padding:var(--space-md)}}

/* ===== BRIDGE ===== */
.bridge-grid{display:grid;grid-template-columns:1fr 1fr;gap:var(--space-xl);align-items:start}
.bridge-content p{font-size:clamp(12px,0.9vw,14px);color:#475569;line-height:1.5;margin-bottom:var(--space-lg)}
.bridge-benefits{display:grid;gap:var(--space-xs);margin-bottom:var(--space-lg)}
.bridge-benefits li{font-size:clamp(11px,0.8vw,13px);color:#1e293b;display:flex;align-items:center;gap:7px}
.bridge-benefits li svg{width:14px;height:14px;flex-shrink:0}
.bridge-meta{display:grid;grid-template-columns:repeat(3,1fr);gap:var(--space-sm);margin-bottom:var(--space-lg);background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:var(--space-md);text-align:center}
.bridge-meta-item span{display:block;font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:0.3px;margin-bottom:1px}
.bridge-meta-item strong{font-size:clamp(11px,0.8vw,13px);color:var(--navy);font-weight:600}
.bridge-steps{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:var(--space-md) var(--space-lg)}
.bridge-steps h4{font-size:clamp(11px,0.8vw,13px);font-weight:600;color:var(--navy);margin-bottom:var(--space-sm);display:flex;align-items:center;gap:5px}
.bridge-steps h4 svg{width:14px;height:14px}
.bridge-steps ol{list-style:none;counter-reset:step;display:grid;gap:var(--space-xs)}
.bridge-steps ol li{counter-increment:step;font-size:clamp(11px,0.8vw,13px);color:#475569;display:flex;align-items:center;gap:7px}
.bridge-steps ol li::before{content:counter(step);width:20px;height:20px;background:var(--navy);color:var(--teal-light);border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0}
.bridge-arch{background:linear-gradient(135deg,var(--navy),#163A5F);border-radius:10px;padding:var(--space-xl) var(--space-lg);text-align:center;border:1px solid rgba(255,255,255,0.06)}
.bridge-arch h3{font-size:clamp(14px,1.1vw,16px);color:#fff;font-weight:700;margin-bottom:2px}
.bridge-arch p{font-size:clamp(10px,0.8vw,12px);color:#94a3b8;margin-bottom:var(--space-lg);max-width:260px;margin-left:auto;margin-right:auto}
.bridge-arch-diagram{display:flex;align-items:center;justify-content:center;gap:var(--space-xs);margin-bottom:var(--space-lg);flex-wrap:wrap}
.bridge-arch-node{background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);border-radius:7px;padding:var(--space-sm) var(--space-md);text-align:center;min-width:80px}
.bridge-arch-node svg{width:18px;height:18px;margin-bottom:3px}
.bridge-arch-node strong{display:block;font-size:clamp(9px,0.7vw,11px);color:#e2e8f0;font-weight:600}
.bridge-arch-node span{font-size:8px;color:#64748b}
.bridge-arch-arrow{color:var(--teal-light);font-size:16px;font-weight:700}
.bridge-arch-tags{display:flex;justify-content:center;gap:var(--space-sm);flex-wrap:wrap}
.bridge-arch-tags span{display:flex;align-items:center;gap:4px;font-size:clamp(9px,0.7vw,11px);color:#94a3b8}
.bridge-arch-tags span svg{width:12px;height:12px}
@media(max-width:768px){.bridge-grid{grid-template-columns:1fr;gap:var(--space-lg)}}

/* ===== PRICING ===== */
.pricing-intro{text-align:center;margin-bottom:var(--space-xl)}
.pricing-intro .offer-badge{display:inline-flex;align-items:center;gap:6px;background:var(--gold);color:#fff;font-size:clamp(10px,0.7vw,12px);font-weight:700;padding:var(--space-xs) var(--space-lg);border-radius:999px;margin-bottom:var(--space-xs);text-transform:uppercase;letter-spacing:0.4px}
.pricing-intro p{font-size:clamp(11px,0.8vw,13px);color:#64748b}
.pricing-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:var(--space-md);align-items:start}
.pricing-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:var(--space-xl) var(--space-lg);position:relative;transition:box-shadow .2s}
.pricing-card:hover{box-shadow:0 6px 20px rgba(0,0,0,0.04)}
.pricing-card.featured{border-color:var(--gold);box-shadow:0 4px 20px rgba(198,146,20,0.08);border-width:2px}
.pricing-original{font-size:clamp(12px,0.9vw,14px);color:#94a3b8;text-decoration:line-through;margin-bottom:1px}
.pricing-save{font-size:clamp(9px,0.7vw,11px);color:var(--gold);font-weight:700;margin-bottom:var(--space-xs)}
.pricing-badge{position:absolute;top:-9px;left:50%;transform:translateX(-50%);background:var(--gold);color:#fff;font-size:9px;font-weight:700;padding:3px 12px;border-radius:999px;text-transform:uppercase;letter-spacing:0.4px}
.pricing-name{font-size:clamp(11px,0.8vw,13px);font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;margin-bottom:3px}
.pricing-price{font-size:clamp(24px,2.2vw,32px);font-weight:800;color:var(--navy);letter-spacing:-0.6px;margin-bottom:1px}
.pricing-price span{font-size:clamp(11px,0.8vw,13px);font-weight:500;color:#64748b;letter-spacing:0}
.pricing-desc{font-size:clamp(10px,0.8vw,12px);color:#64748b;margin-bottom:var(--space-lg)}
.pricing-divider{height:1px;background:#e2e8f0;margin-bottom:var(--space-lg)}
.pricing-features{display:grid;gap:var(--space-sm);margin-bottom:var(--space-lg)}
.pricing-features li{font-size:clamp(11px,0.8vw,13px);color:#475569;display:flex;align-items:center;gap:7px}
.pricing-features li svg{width:13px;height:13px;flex-shrink:0}
.pricing-btn{display:block;width:100%;padding:var(--space-sm) var(--space-md);border-radius:7px;font-size:clamp(11px,0.8vw,13px);font-weight:600;text-align:center;border:none;cursor:pointer;transition:all .2s}
.pricing-btn.primary{background:var(--teal);color:#fff}
.pricing-btn.primary:hover{background:#115e59}
.pricing-btn.secondary{background:#f1f5f9;color:#1e293b}
.pricing-btn.secondary:hover{background:#e2e8f0}
.pricing-btn.orange{background:var(--gold);color:#fff}
.pricing-btn.orange:hover{background:#A67A0F}
.pricing-legal{text-align:center;margin-top:var(--space-sm);font-size:clamp(9px,0.7vw,11px);color:#94a3b8}
.pricing-includes{text-align:center;margin-top:var(--space-lg);padding:var(--space-md) var(--space-lg);background:#fff;border-radius:10px;border:1px solid #e2e8f0}
.pricing-includes h4{font-size:clamp(12px,0.9vw,14px);font-weight:600;color:var(--navy);margin-bottom:var(--space-sm)}
.pricing-includes-grid{display:flex;flex-wrap:wrap;gap:var(--space-xs) var(--space-lg);justify-content:center}
.pricing-includes-grid span{font-size:clamp(10px,0.8vw,12px);color:#475569;display:flex;align-items:center;gap:4px}
.pricing-includes-grid span svg{width:12px;height:12px;flex-shrink:0}
@media(max-width:860px){.pricing-grid{grid-template-columns:1fr;gap:var(--space-md);max-width:380px;margin:0 auto}.pricing-card.featured{transform:none}}
@media(max-width:480px){.pricing-card{padding:var(--space-lg)}}

/* ===== CTA ===== */
.cta{background:linear-gradient(135deg,var(--navy) 0,#163A5F 50%,var(--navy-light) 100%);padding:var(--space-3xl) 0;text-align:center}
.cta h2{font-size:var(--fs-section-heading);font-weight:800;color:#fff;letter-spacing:-0.3px;margin-bottom:var(--space-sm)}
.cta p{font-size:var(--fs-section-sub);color:#94a3b8;max-width:480px;margin:0 auto var(--space-xl);line-height:1.5}
.cta-actions{display:flex;gap:var(--space-sm);justify-content:center;flex-wrap:wrap}
@media(max-width:480px){.cta-actions{flex-direction:column;align-items:center}.cta-actions .btn-primary,.cta-actions .btn-secondary,.cta-actions .btn-outline-light{width:100%;max-width:280px}}

/* ===== CONTACT ===== */
.contact-grid{display:grid;grid-template-columns:1fr 1fr;gap:var(--space-xl);align-items:center}
.contact-info h3{font-size:clamp(16px,1.3vw,20px);font-weight:700;color:var(--navy);margin-bottom:var(--space-xs)}
.contact-info p{font-size:clamp(12px,0.9vw,14px);color:#64748b;margin-bottom:var(--space-lg);line-height:1.5}
.contact-details{display:grid;gap:var(--space-sm);margin-bottom:var(--space-lg)}
.contact-details li{font-size:clamp(11px,0.8vw,13px);color:#1e293b;display:flex;align-items:center;gap:7px}
.contact-details li svg{width:16px;height:16px;flex-shrink:0}
.contact-visual{background:linear-gradient(135deg,#f0fdfa,#ccfbf1);border-radius:10px;padding:var(--space-xl) var(--space-lg);text-align:center;border:1px solid #a7f3d0}
.contact-visual svg{width:36px;height:36px;margin-bottom:var(--space-sm)}
.contact-visual h4{font-size:clamp(13px,1vw,15px);color:var(--teal);font-weight:700;margin-bottom:var(--space-xs)}
.contact-visual p{font-size:clamp(10px,0.8vw,12px);color:#475569;max-width:220px;margin:0 auto var(--space-lg)}
@media(max-width:640px){.contact-grid{grid-template-columns:1fr;gap:var(--space-lg)}}

/* ===== STICKY CTA ===== */
.sticky-cta{position:fixed;bottom:0;left:0;right:0;background:var(--navy);border-top:1px solid rgba(255,255,255,0.08);padding:var(--space-xs) 0;z-index:200}
.sticky-cta .container{display:flex;align-items:center;justify-content:center;gap:var(--space-sm);flex-wrap:wrap}
.sticky-cta span{font-size:clamp(11px,0.8vw,13px);color:#e2e8f0;font-weight:500}
.sticky-cta .btn-primary,.sticky-cta .btn-outline-light,.sticky-cta .btn-orange{padding:var(--space-xs) var(--space-md);font-size:clamp(10px,0.7vw,12px)}
@media(max-width:480px){.sticky-cta span{display:none}body{padding-bottom:48px}}

/* ===== FOOTER ===== */
.footer{background:#071526;padding:var(--space-xl) 0 var(--space-lg);border-top:1px solid rgba(255,255,255,0.04)}
.footer-grid{display:grid;grid-template-columns:2fr 1fr 1fr;gap:var(--space-xl);margin-bottom:var(--space-xl)}
.footer-brand .footer-logo{font-size:clamp(16px,1.2vw,20px);font-weight:800;color:#fff;margin-bottom:3px}
.footer-brand .footer-logo span{color:var(--teal-light)}
.footer-brand p{font-size:clamp(10px,0.7vw,12px);color:#64748b;line-height:1.5;max-width:260px}
.footer-col h4{font-size:clamp(10px,0.7vw,12px);font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.4px;margin-bottom:var(--space-md)}
.footer-col ul{display:grid;gap:var(--space-xs)}
.footer-col a{font-size:clamp(11px,0.8vw,13px);color:#cbd5e1;transition:color .2s}
.footer-col a:hover{color:var(--teal-light)}
.footer-bottom{border-top:1px solid rgba(255,255,255,0.04);padding-top:var(--space-md);text-align:center;font-size:clamp(9px,0.7vw,11px);color:#475569}
@media(max-width:640px){.footer-grid{grid-template-columns:1fr;gap:var(--space-lg)}.footer-brand p{max-width:100%}}
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
      <div class="positioning-badge">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="10" height="10"><polyline points="20 6 9 17 4 12"/></svg>
        Financial Statement Preparation Platform
      </div>
      <h1>Financial Statements<br><span>Ready in Minutes</span></h1>
      <p class="hero-sub">Trial Balance In &mdash; Financial Statements Out</p>
      <p class="hero-desc">Import Trial Balance from Tally and generate professional financial statements, schedules, notes and comparative reports in a structured workflow.</p>
      <div class="hero-kpis">
        <div class="hero-kpi">
          <div class="hero-kpi-value">100%</div>
          <div class="hero-kpi-label">Structured Reporting</div>
        </div>
        <div class="hero-kpi">
          <div class="hero-kpi-value">6</div>
          <div class="hero-kpi-label">Reporting Frameworks</div>
        </div>
        <div class="hero-kpi">
          <div class="hero-kpi-value">3</div>
          <div class="hero-kpi-label">PDF &bull; Excel &bull; Word</div>
        </div>
      </div>
      <div class="hero-actions">
        <a href="#pricing" class="btn-primary">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
          Choose Your Plan
        </a>
        <a href="login.php" class="btn-secondary">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
          Login
        </a>
      </div>
    </div>
    <div class="hero-visual">
      <div class="hero-screen-main">
        <div class="hero-screen-header">
          <div class="hero-screen-dots"><span></span><span></span><span></span></div>
          <span class="hero-screen-title">Generated Output &mdash; Balance Sheet (Schedule III)</span>
        </div>
        <div class="hero-screen-body" style="background:#fff;border-radius:0 0 10px 10px">
          <div class="sample-output" style="border:none;box-shadow:none">
            <div class="so-header">Balance Sheet as at 31 March 2026</div>
            <div class="so-section-label">EQUITY &amp; LIABILITIES</div>
            <div class="so-row"><span class="so-label">Shareholders' Funds</span></div>
            <div class="so-row" style="padding-left:12px"><span class="so-label">Share Capital</span><span class="so-value">50,00,000</span></div>
            <div class="so-row" style="padding-left:12px"><span class="so-label">Reserves &amp; Surplus</span><span class="so-value">12,45,000</span></div>
            <div class="so-row"><span class="so-label">Non-Current Liabilities</span></div>
            <div class="so-row" style="padding-left:12px"><span class="so-label">Long-Term Borrowings</span><span class="so-value">18,00,000</span></div>
            <div class="so-row"><span class="so-label">Current Liabilities</span></div>
            <div class="so-row" style="padding-left:12px"><span class="so-label">Trade Payables</span><span class="so-value">8,20,000</span></div>
            <div class="so-row so-total"><span class="so-label">Total</span><span class="so-value">88,65,000</span></div>
            <div class="so-section-label" style="margin-top:6px">ASSETS</div>
            <div class="so-row"><span class="so-label">Non-Current Assets</span></div>
            <div class="so-row" style="padding-left:12px"><span class="so-label">Fixed Assets</span><span class="so-value">42,00,000</span></div>
            <div class="so-row" style="padding-left:12px"><span class="so-label">Intangible Assets</span><span class="so-value">5,50,000</span></div>
            <div class="so-row"><span class="so-label">Current Assets</span></div>
            <div class="so-row" style="padding-left:12px"><span class="so-label">Inventories</span><span class="so-value">18,75,000</span></div>
            <div class="so-row" style="padding-left:12px"><span class="so-label">Trade Receivables</span><span class="so-value">14,90,000</span></div>
            <div class="so-row" style="padding-left:12px"><span class="so-label">Cash &amp; Bank</span><span class="so-value">7,50,000</span></div>
            <div class="so-row so-total"><span class="so-label">Total</span><span class="so-value">88,65,000</span></div>
          </div>
        </div>
      </div>
      <div class="hero-screens">
        <div class="hero-screen-sm">
          <div class="hero-screen-header">
            <div class="hero-screen-dots"><span></span><span></span><span></span></div>
            <span class="hero-screen-title">Statement of Profit &amp; Loss</span>
          </div>
          <div class="hero-screen-body" style="background:#fff;border-radius:0 0 8px 8px">
            <div class="sample-output sample-output-sm" style="border:none;box-shadow:none">
              <div class="so-header">Profit &amp; Loss for the year ended 31 Mar 2026</div>
              <div class="so-row"><span class="so-label">Revenue from Operations</span><span class="so-value">1,25,00,000</span></div>
              <div class="so-row"><span class="so-label">Other Income</span><span class="so-value">3,20,000</span></div>
              <div class="so-row" style="border-bottom:1px solid #e2e8f0;padding-bottom:3px;margin-bottom:3px"><span class="so-label" style="font-weight:600">Total Revenue</span><span class="so-value" style="font-weight:700">1,28,20,000</span></div>
              <div class="so-row"><span class="so-label">Cost of Materials</span><span class="so-value">52,00,000</span></div>
              <div class="so-row"><span class="so-label">Employee Benefits</span><span class="so-value">28,50,000</span></div>
              <div class="so-row"><span class="so-label">Finance Costs</span><span class="so-value">2,30,000</span></div>
              <div class="so-row"><span class="so-label">Depreciation</span><span class="so-value">4,20,000</span></div>
              <div class="so-row"><span class="so-label">Other Expenses</span><span class="so-value">18,60,000</span></div>
              <div class="so-row so-total"><span class="so-label">Profit Before Tax</span><span class="so-value">22,60,000</span></div>
              <div class="so-row"><span class="so-label">Tax Expense</span><span class="so-value" class="neg">5,65,000</span></div>
              <div class="so-row so-total"><span class="so-label">Profit for the Year</span><span class="so-value">16,95,000</span></div>
            </div>
          </div>
        </div>
        <div class="hero-screen-sm">
          <div class="hero-screen-header">
            <div class="hero-screen-dots"><span></span><span></span><span></span></div>
            <span class="hero-screen-title">Notes to Accounts</span>
          </div>
          <div class="hero-screen-body" style="background:#fff;border-radius:0 0 8px 8px">
            <div class="sample-output sample-output-sm" style="border:none;box-shadow:none">
              <div class="so-header">Notes Forming Part of Financial Statements</div>
              <div class="so-section-label">Note 1 &mdash; Share Capital</div>
              <div class="so-row"><span class="so-label">Authorised Capital</span><span class="so-value">1,00,00,000</span></div>
              <div class="so-row"><span class="so-label">Issued Capital</span><span class="so-value">50,00,000</span></div>
              <div class="so-row"><span class="so-label">Subscribed &amp; Paid-up</span><span class="so-value">50,00,000</span></div>
              <div class="so-section-label">Note 2 &mdash; Fixed Assets</div>
              <div class="so-row"><span class="so-label">Tangible</span><span class="so-value">38,00,000</span></div>
              <div class="so-row"><span class="so-label">Intangible</span><span class="so-value">4,00,000</span></div>
              <div class="so-row so-total"><span class="so-label">Total</span><span class="so-value">42,00,000</span></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===== COMPLIANCE TRUST STRIP ===== -->
<div class="compliance-strip">
  <div class="container">
    <span class="compliance-item"><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal-light)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Schedule III Ready</span>
    <span class="compliance-item"><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal-light)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>LLP Reporting</span>
    <span class="compliance-item"><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal-light)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Comparative Statements</span>
    <span class="compliance-item"><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal-light)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>MCA Aligned Reporting</span>
    <span class="compliance-item"><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal-light)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Tally Prime Compatible</span>
    <span class="compliance-item"><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal-light)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>PDF &bull; Excel &bull; Word</span>
  </div>
</div>

<!-- ===== CREDIBILITY BAR ===== -->
<div class="cred-bar">
  <div class="container">
    <div class="cred-badges">
      <span class="cred-badge">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Chartered Accountants
      </span>
      <span class="cred-badge">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Tax Practitioners
      </span>
      <span class="cred-badge">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Audit Firms
      </span>
      <span class="cred-badge">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        MSME Consultants
      </span>
      <span class="cred-badge">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Finance Professionals
      </span>
    </div>
    <span class="cred-developer">Built by Tax &amp; Compliance Professionals &mdash; Developed by <strong>E Tax Advisors Private Limited</strong></span>
  </div>
</div>

<!-- ===== SAMPLE OUTPUT SHOWCASE ===== -->
<section class="section section-alt" id="workspace">
  <div class="container">
    <div class="section-header">
      <h2>Professional Financial Statements</h2>
      <p>e-BAL generates audit-ready Balance Sheet, Profit &amp; Loss, Cash Flow, Schedules, and Notes to Accounts in Schedule III, LLP, Partnership, Proprietorship, Trust &amp; Society formats.</p>
    </div>
    <div class="inside-grid">
      <div class="inside-card">
        <div class="inside-card-visual" style="background:#fff;padding:8px">
          <div class="sample-output" style="border:1px solid #e2e8f0;box-shadow:none;font-size:7px;padding:6px;width:100%">
            <div class="so-header" style="font-size:7px">Balance Sheet</div>
            <div class="so-row" style="font-size:6px"><span>Share Capital</span><span>50,00,000</span></div>
            <div class="so-row" style="font-size:6px"><span>Reserves</span><span>12,45,000</span></div>
            <div class="so-row" style="font-size:6px"><span>Borrowings</span><span>18,00,000</span></div>
            <div class="so-row" style="font-size:6px"><span>Payables</span><span>8,20,000</span></div>
            <div class="so-row so-total" style="font-size:6px"><span>Total</span><span>88,65,000</span></div>
            <div class="so-row" style="font-size:6px;margin-top:2px"><span>Fixed Assets</span><span>42,00,000</span></div>
            <div class="so-row" style="font-size:6px"><span>Current Assets</span><span>46,65,000</span></div>
            <div class="so-row so-total" style="font-size:6px"><span>Total</span><span>88,65,000</span></div>
          </div>
        </div>
        <div class="inside-card-caption">
          <strong>Balance Sheet</strong>
          <span>Schedule III, LLP, Partnership formats</span>
        </div>
      </div>
      <div class="inside-card">
        <div class="inside-card-visual" style="background:#fff;padding:8px">
          <div class="sample-output" style="border:1px solid #e2e8f0;box-shadow:none;font-size:7px;padding:6px;width:100%">
            <div class="so-header" style="font-size:7px">Profit &amp; Loss</div>
            <div class="so-row" style="font-size:6px"><span>Revenue</span><span>1,25,00,000</span></div>
            <div class="so-row" style="font-size:6px"><span>Other Income</span><span>3,20,000</span></div>
            <div class="so-row" style="font-size:6px;border-bottom:1px solid #e2e8f0;padding-bottom:2px"><span style="font-weight:600">Total</span><span style="font-weight:700">1,28,20,000</span></div>
            <div class="so-row" style="font-size:6px"><span>Expenses</span><span>1,05,60,000</span></div>
            <div class="so-row so-total" style="font-size:6px"><span>PBT</span><span>22,60,000</span></div>
            <div class="so-row" style="font-size:6px"><span>Tax</span><span>5,65,000</span></div>
            <div class="so-row so-total" style="font-size:6px"><span>PAT</span><span>16,95,000</span></div>
          </div>
        </div>
        <div class="inside-card-caption">
          <strong>Profit &amp; Loss</strong>
          <span>Comparative year-over-year statements</span>
        </div>
      </div>
      <div class="inside-card">
        <div class="inside-card-visual" style="background:#fff;padding:8px">
          <div class="sample-output" style="border:1px solid #e2e8f0;box-shadow:none;font-size:7px;padding:6px;width:100%">
            <div class="so-header" style="font-size:7px">Schedules &amp; Notes</div>
            <div class="so-section-label" style="font-size:6px">Schedule 1 &mdash; Share Capital</div>
            <div class="so-row" style="font-size:6px"><span>Authorised</span><span>1,00,00,000</span></div>
            <div class="so-row" style="font-size:6px"><span>Issued</span><span>50,00,000</span></div>
            <div class="so-section-label" style="font-size:6px;margin-top:3px">Schedule 2 &mdash; Fixed Assets</div>
            <div class="so-row" style="font-size:6px"><span>Tangible</span><span>38,00,000</span></div>
            <div class="so-row" style="font-size:6px"><span>Intangible</span><span>4,00,000</span></div>
            <div class="so-section-label" style="font-size:6px;margin-top:3px">Schedule 3 &mdash; Inventories</div>
            <div class="so-row" style="font-size:6px"><span>RM &amp; WIP</span><span>18,75,000</span></div>
          </div>
        </div>
        <div class="inside-card-caption">
          <strong>Schedules &amp; Notes</strong>
          <span>Auto-generated detailed disclosures</span>
        </div>
      </div>
      <div class="inside-card">
        <div class="inside-card-visual" style="background:#fff;padding:8px">
          <div class="sample-output" style="border:1px solid #e2e8f0;box-shadow:none;font-size:7px;padding:6px;width:100%">
            <div class="so-header" style="font-size:7px">Cash Flow Statement</div>
            <div class="so-section-label" style="font-size:6px">Operating Activities</div>
            <div class="so-row" style="font-size:6px"><span>PBT</span><span>22,60,000</span></div>
            <div class="so-row" style="font-size:6px"><span>Depreciation</span><span>4,20,000</span></div>
            <div class="so-row" style="font-size:6px"><span>Working Capital</span><span>(2,30,000)</span></div>
            <div class="so-section-label" style="font-size:6px;margin-top:3px">Investing Activities</div>
            <div class="so-row" style="font-size:6px"><span>Asset Purchase</span><span>(8,00,000)</span></div>
            <div class="so-section-label" style="font-size:6px;margin-top:3px">Financing Activities</div>
            <div class="so-row" style="font-size:6px"><span>Loan Repayment</span><span>(3,00,000)</span></div>
          </div>
        </div>
        <div class="inside-card-caption">
          <strong>Cash Flow Statement</strong>
          <span>Indirect method as per Schedule III</span>
        </div>
      </div>
      <div class="inside-card">
        <div class="inside-card-visual" style="background:#fff;padding:8px">
          <div class="sample-output" style="border:1px solid #e2e8f0;box-shadow:none;font-size:7px;padding:6px;width:100%">
            <div class="so-header" style="font-size:7px">Export Formats</div>
            <div class="so-section-label" style="font-size:6px">PDF</div>
            <div class="so-row" style="font-size:6px"><span>Audit-ready print layout</span><span style="color:var(--teal)">&#10003;</span></div>
            <div class="so-section-label" style="font-size:6px;margin-top:3px">Excel (XLSX)</div>
            <div class="so-row" style="font-size:6px"><span>Editable schedules</span><span style="color:var(--teal)">&#10003;</span></div>
            <div class="so-section-label" style="font-size:6px;margin-top:3px">Word (DOCX)</div>
            <div class="so-row" style="font-size:6px"><span>Notes &amp; reports</span><span style="color:var(--teal)">&#10003;</span></div>
            <div class="so-section-label" style="font-size:6px;margin-top:3px">Comparative</div>
            <div class="so-row" style="font-size:6px"><span>Current vs Previous year</span><span style="color:var(--teal)">&#10003;</span></div>
          </div>
        </div>
        <div class="inside-card-caption">
          <strong>Export Formats</strong>
          <span>PDF, Excel &amp; Word with comparatives</span>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===== REPORTING FRAMEWORK ===== -->
<section class="section" id="reporting">
  <div class="container">
    <div class="section-header">
      <h2>Reporting Framework</h2>
      <p>Six regulation-ready financial statement types supported out of the box.</p>
    </div>
    <div class="rf-grid">
      <div class="rf-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
        <span>Companies Act Schedule III</span>
      </div>
      <div class="rf-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
        <span>LLP Financial Statements</span>
      </div>
      <div class="rf-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
        <span>Proprietorship Accounts</span>
      </div>
      <div class="rf-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
        <span>Partnership Accounts</span>
      </div>
      <div class="rf-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
        <span>Trust Financial Statements</span>
      </div>
      <div class="rf-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
        <span>Society Financial Statements</span>
      </div>
    </div>
  </div>
</section>

<!-- ===== BUILT FOR PROFESSIONAL PRACTICE ===== -->
<!-- merged into credibility bar above -->

<!-- ===== TRADITIONAL vs e-BAL ===== -->
<section class="section" id="compare">
  <div class="container">
    <div class="section-header">
      <h2>Traditional Process vs e-BAL</h2>
      <p>See how e-BAL eliminates manual work at every stage of statement preparation.</p>
    </div>
    <div class="compare-grid">
      <div class="compare-col traditional">
        <h3>
          <svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          Traditional Process
        </h3>
        <ul>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Manual mapping &amp; classification</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Excel-based schedule preparation</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Manually typed notes &amp; disclosures</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Repetitive rework each period</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>High review effort &amp; error risk</li>
        </ul>
      </div>
      <div class="compare-col ebal">
        <h3>
          <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          e-BAL
        </h3>
        <ul>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Automated AI-assisted mapping</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Structured schedule generation</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Auto-generated notes &amp; disclosures</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Comparative year-over-year reporting</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Faster finalisation with built-in validation</li>
        </ul>
      </div>
    </div>
    <div class="compare-metrics">
      <div class="compare-metric">
        <div class="compare-metric-value">70%</div>
        <div class="compare-metric-label">Time Saved</div>
      </div>
      <div class="compare-metric">
        <div class="compare-metric-value">90%</div>
        <div class="compare-metric-label">Errors Reduced</div>
      </div>
      <div class="compare-metric">
        <div class="compare-metric-value">100%</div>
        <div class="compare-metric-label">Standardisation</div>
      </div>
    </div>
  </div>
</section>

<!-- ===== KEY CAPABILITIES ===== -->
<section class="section section-alt" id="features">
  <div class="container">
    <div class="section-header">
      <h2>Key Capabilities</h2>
      <p>Everything a CA firm needs for professional financial reporting.</p>
    </div>
    <div class="features-grid">
      <div class="feature-card">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        <h3>Trial Balance Import</h3>
        <p>Import from Tally Prime, Tally ERP 9, XML, Excel, or CSV.</p>
        <div class="feature-tags">
          <span>Tally Prime</span>
          <span>Tally ERP 9</span>
          <span>XML</span>
          <span>Excel</span>
          <span>CSV</span>
        </div>
      </div>
      <div class="feature-card">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 22 8.5 22 15.5 12 22 2 15.5 2 8.5 12 2"/><line x1="12" y1="22" x2="12" y2="15.5"/><polyline points="22 8.5 12 15.5 2 8.5"/></svg>
        <h3>Smart Ledger Mapping</h3>
        <p>AI-assisted classification with conflict detection and override.</p>
        <div class="feature-tags">
          <span>Auto-classify</span>
          <span>Smart suggestions</span>
          <span>Conflict detection</span>
        </div>
      </div>
      <div class="feature-card">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
        <h3>Financial Statements</h3>
        <p>Schedule III, LLP, Partnership, Proprietorship formats.</p>
        <div class="feature-tags">
          <span>Corporate</span>
          <span>LLP</span>
          <span>Partnership</span>
          <span>Proprietorship</span>
        </div>
      </div>
      <div class="feature-card">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 22 8.5 22 15.5 12 22 2 15.5 2 8.5 12 2"/><line x1="12" y1="22" x2="12" y2="15.5"/><polyline points="22 8.5 12 15.5 2 8.5"/></svg>
        <h3>Trust &amp; Society Reporting</h3>
        <p>Income &amp; Expenditure and Receipts &amp; Payments formats.</p>
        <div class="feature-tags">
          <span>Income &amp; Expenditure</span>
          <span>Receipts &amp; Payments</span>
        </div>
      </div>
      <div class="feature-card">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        <h3>Comparative Statements</h3>
        <p>Current year vs previous year side-by-side comparison.</p>
        <div class="feature-tags">
          <span>Current Year</span>
          <span>Previous Year</span>
        </div>
      </div>
      <div class="feature-card">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        <h3>Professional Exports</h3>
        <p>Export to PDF, Excel, and Word formats with audit-ready formatting.</p>
        <div class="feature-tags">
          <span>PDF</span>
          <span>Excel</span>
          <span>Word</span>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===== e-BAL SMART BRIDGE ===== -->
<section class="section section-alt" id="bridge">
  <div class="container">
    <div class="section-header">
      <h2>e-BAL Smart Bridge</h2>
      <p>Enterprise-grade connector for secure Tally-to-cloud data synchronisation.</p>
    </div>
    <div class="bridge-grid">
      <div class="bridge-content">
        <p>Smart Bridge is an enterprise connector that runs on your Windows environment and establishes a trusted, encrypted channel between Tally and the e-BAL platform. No manual file transfers, no data leaks, no configuration headaches.</p>
        <ul class="bridge-benefits">
          <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            One-click synchronisation from Tally
          </li>
          <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            Tally Prime &amp; Tally ERP 9 support
          </li>
          <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            XML-based data integration with validation
          </li>
          <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            Token-based secure authentication &amp; audit logging
          </li>
          <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            Real-time sync status monitoring
          </li>
        </ul>
        <a href="downloads/e-bal-bridge-setup.exe" class="btn-primary">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Download Smart Bridge
        </a>
        <div class="bridge-meta">
          <div class="bridge-meta-item">
            <span>Version</span>
            <strong>2.0.0</strong>
          </div>
          <div class="bridge-meta-item">
            <span>Release</span>
            <strong>June 2026</strong>
          </div>
          <div class="bridge-meta-item">
            <span>Size</span>
            <strong>12.5 MB</strong>
          </div>
        </div>
        <div class="bridge-steps">
          <h4>
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            Installation Steps
          </h4>
          <ol>
            <li>Download the Smart Bridge installer</li>
            <li>Run the installer on your Windows machine</li>
            <li>Configure Tally connection settings</li>
            <li>Authenticate with your e-BAL token</li>
          </ol>
        </div>
      </div>
      <div class="bridge-arch">
        <h3>Enterprise Connector</h3>
        <p>Secure, audited, and built for production accounting environments.</p>
        <div class="bridge-arch-diagram">
          <div class="bridge-arch-node">
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal-light)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
            <strong>Tally</strong>
            <span>Prime / ERP 9</span>
          </div>
          <span class="bridge-arch-arrow">&rarr;</span>
          <div class="bridge-arch-node">
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal-light)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <strong>Smart Bridge</strong>
            <span>Desktop Agent</span>
          </div>
          <span class="bridge-arch-arrow">&rarr;</span>
          <div class="bridge-arch-node">
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal-light)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <strong>e-BAL Cloud</strong>
            <span>SaaS Platform</span>
          </div>
        </div>
        <div class="bridge-arch-tags">
          <span><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal-light)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="12" height="12"><polyline points="20 6 9 17 4 12"/></svg>TLS Encrypted</span>
          <span><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal-light)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="12" height="12"><polyline points="20 6 9 17 4 12"/></svg>Audit Logged</span>
          <span><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal-light)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="12" height="12"><polyline points="20 6 9 17 4 12"/></svg>Zero Config</span>
          <span><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal-light)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="12" height="12"><polyline points="20 6 9 17 4 12"/></svg>Token Auth</span>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===== PRICING ===== -->
<section class="section" id="pricing">
  <div class="container">
    <div class="section-header">
      <h2>Simple &amp; Transparent Pricing</h2>
      <p>Choose the right plan for your practice. All plans include Smart Bridge.</p>
    </div>
    <div class="pricing-intro">
      <div class="offer-badge">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="12" height="12"><polygon points="12 2 22 8.5 22 15.5 12 22 2 15.5 2 8.5"/></svg>
        Introductory Launch Offer
      </div>
      <p>Save 50% off regular pricing during our introductory period. Prices subject to revision after the introductory offer period.</p>
    </div>
    <div class="pricing-grid">
      <div class="pricing-card">
        <div class="pricing-name">e-BAL Base</div>
        <div class="pricing-original">₹15,000/yr</div>
        <div class="pricing-price">₹7,499 <span>/ Year</span></div>
        <div class="pricing-save">Save 50%</div>
        <p class="pricing-desc">For individual practitioners and sole proprietors.</p>
        <div class="pricing-divider"></div>
        <ul class="pricing-features">
          <li><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>1 User</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>10 Entities</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>PDF &amp; Excel Export</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Smart Bridge Included</li>
        </ul>
        <a href="upgrade.php" class="pricing-btn secondary">Get Started</a>
      </div>
      <div class="pricing-card featured">
        <div class="pricing-badge">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="10" height="10"><polygon points="12 2 22 8.5 22 15.5 12 22 2 15.5 2 8.5"/></svg>
          Most Popular
        </div>
        <div class="pricing-name">e-BAL Pro</div>
        <div class="pricing-original">₹30,000/yr</div>
        <div class="pricing-price">₹14,999 <span>/ Year</span></div>
        <div class="pricing-save">Save 50%</div>
        <p class="pricing-desc">For growing CA firms and tax practices.</p>
        <div class="pricing-divider"></div>
        <ul class="pricing-features">
          <li><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>10 Users</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>25 Entities</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>PDF, Excel &amp; Word Export</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Smart Bridge Included</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Priority Support</li>
        </ul>
        <a href="upgrade.php" class="pricing-btn orange">Get Started</a>
      </div>
      <div class="pricing-card">
        <div class="pricing-name">e-BAL Elite</div>
        <div class="pricing-original">₹50,000/yr</div>
        <div class="pricing-price">₹29,999 <span>/ Year</span></div>
        <div class="pricing-save">Save 40%</div>
        <p class="pricing-desc">For established firms with large client bases.</p>
        <div class="pricing-divider"></div>
        <ul class="pricing-features">
          <li><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Unlimited Users</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Unlimited Entities</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>All Export Formats</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Smart Bridge Included</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Dedicated Account Manager</li>
        </ul>
        <a href="https://etaxadv.com/contact" class="pricing-btn secondary">Contact Sales</a>
      </div>
    </div>
    <p class="pricing-legal">* Prices subject to revision after the introductory offer period. All plans include Smart Bridge, comparative reporting, and scheduled notes.</p>
    <div class="pricing-includes">
      <h4>All Plans Include</h4>
      <div class="pricing-includes-grid">
        <span><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Smart Bridge</span>
        <span><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Financial Statements</span>
        <span><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Comparative Reports</span>
        <span><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>PDF Export</span>
        <span><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Excel Export</span>
        <span><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Word Export</span>
      </div>
    </div>
  </div>
</section>

<!-- ===== CTA ===== -->
<section class="cta" id="cta">
  <div class="container">
    <h2>Ready to Prepare Financial Statements Faster?</h2>
    <p>No accounting software migration required. Start with your Tally data today.</p>
    <div class="cta-actions">
      <a href="#pricing" class="btn-primary">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        Choose Your Plan
      </a>
      <a href="login.php" class="btn-outline-light">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
        Login
      </a>
      <a href="#bridge" class="btn-outline-light">Download Smart Bridge</a>
      <a href="https://etaxadv.com/contact" class="btn-outline-light">Request a Demo</a>
    </div>
  </div>
</section>

<!-- ===== CONTACT ===== -->
<section class="section section-alt" id="contact">
  <div class="container">
    <div class="section-header">
      <h2>Get in Touch</h2>
      <p>Schedule a personalised demo or ask us anything about e-BAL.</p>
    </div>
    <div class="contact-grid">
      <div class="contact-info">
        <h3>We're Here to Help</h3>
        <p>Our team can walk you through the platform and answer any questions about your specific practice needs.</p>
        <ul class="contact-details">
          <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <span>contact@etaxadv.com</span>
          </li>
          <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <span>Visit E Tax Advisors Private Limited</span>
          </li>
        </ul>
        <a href="https://etaxadv.com/contact" class="btn-primary">Contact Sales</a>
      </div>
      <div class="contact-visual">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        <h4>Get a Personalised Demo</h4>
        <p>See how e-BAL can transform your financial statement preparation workflow in a 15-minute walkthrough.</p>
        <a href="https://etaxadv.com/contact" class="btn-outline" style="font-size:12px;padding:9px 20px">Book a Demo</a>
      </div>
    </div>
  </div>
</section>

<!-- ===== STICKY CTA ===== -->
<div class="sticky-cta">
  <div class="container">
    <span>Start preparing statements in minutes:</span>
    <a href="#pricing" class="btn-primary">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="12" height="12"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
      Choose Your Plan
    </a>
    <a href="login.php" class="btn-outline-light">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="12" height="12"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
      Login
    </a>
    <a href="#bridge" class="btn-outline-light">Download Smart Bridge</a>
    <a href="https://etaxadv.com/contact" class="btn-outline-light">Contact Sales</a>
  </div>
</div>

<!-- ===== FOOTER ===== -->
<footer class="footer">
  <div class="container">
    <div class="footer-grid">
      <div class="footer-brand">
        <div class="footer-logo">e<span>-BAL</span></div>
        <p>Financial Statement Preparation Platform by E Tax Advisors Private Limited. Transform your Tally trial balance into professional financial statements.</p>
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
          <li><a href="#features">Capabilities</a></li>
          <li><a href="#pricing">Pricing</a></li>
          <li><a href="#workspace">How It Works</a></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      &copy; 2026 e-BAL &mdash; A product by E Tax Advisors Private Limited. All rights reserved.
      <span style="margin-left:16px;">
        <a href="privacy_policy.php" style="color:#94a3b8;">Privacy Policy</a> &middot;
        <a href="terms_of_service.php" style="color:#94a3b8;">Terms of Service</a> &middot;
        <a href="refund_policy.php" style="color:#94a3b8;">Refund Policy</a>
      </span>
    </div>
  </div>
</footer>

</body>
</html>

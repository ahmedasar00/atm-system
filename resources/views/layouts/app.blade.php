<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'SecureBank - Dashboard')</title>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Your CSS Files -->
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    
    @stack('styles')
</head>
<body>
    <!-- Header -->
    <header class="main-header">
        <div class="header-content">
            <div class="header-left">
                <button class="menu-toggle" id="menu-toggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="logo">
                    <i class="fas fa-shield-alt"></i>
                    <span>SecureBank</span>
                </div>
            </div>
            
            <div class="header-right">
            
                
                <button class="notification-btn">
                    <i class="fas fa-bell"></i>
                    <span class="badge">3</span>
                </button>
                
                <div class="user-menu">
                    <div class="user-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="user-info">
                        <span class="user-name">{{ auth()->user()->name ?? 'John Doe' }}</span>
                        <span class="user-role">Account Holder</span>
                    </div>
                    <i class="fas fa-chevron-down"></i>
                    
                    <div class="user-dropdown">
                        <a href="#"><i class="fas fa-user"></i> My Profile</a>
                        <a href="#"><i class="fas fa-cog"></i> Settings</a>
                        <a href="#"><i class="fas fa-shield-alt"></i> Security</a>
                        @auth
                            @if(auth()->user()->fingerprint_id)
                                <a href="#" style="opacity: 0.6; cursor: default;"><i class="fas fa-fingerprint"></i> Fingerprint Registered ✓</a>
                            @else
                                <a href="#" id="register-fingerprint"><i class="fas fa-fingerprint"></i> Register Fingerprint</a>
                            @endif
                        @endauth
                        <hr>
                        <a href="{{ url('/logout') }}" onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                        <form id="logout-form" action="{{ url('/logout') }}" method="POST" style="display: none;">
                            @csrf
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <nav class="sidebar-nav">
            <a href="{{ url('/dashboard') }}" class="nav-item active">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
            
            <div class="nav-section">
                <span class="nav-section-title">Banking</span>
            </div>
            
            
            <a href="{{ url('/atm/withdraw') }}" class="nav-item">
                <i class="fas fa-money-bill-wave"></i>
                <span>Withdraw</span>
            </a>
            
            <a href="{{ url('/atm/transfer') }}" class="nav-item">
                <i class="fas fa-exchange-alt"></i>
                <span>Transfer Money</span>
            </a>
            
            
            <a href="{{ url('/atm/history') }}" class="nav-item">
                <i class="fas fa-history"></i>
                <span>Transaction History</span>
            </a>
            
            
        </nav>
        
        <div class="sidebar-footer">
            <div class="help-card">
                <i class="fas fa-question-circle"></i>
                <h4>Need Help?</h4>
                <p>Contact our support team</p>
                <button class="btn-help">Get Support</button>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        @if(session('success'))
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span>{{ session('success') }}</span>
                <button class="alert-close">&times;</button>
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span>{{ session('error') }}</span>
                <button class="alert-close">&times;</button>
            </div>
        @endif

        @yield('content')
    </main>

    <!-- Global JS -->
    <script src="{{ asset('js/app.js') }}"></script>
    
    @stack('scripts')

    @auth
        <script src="{{ asset('js/fingerprint-register.js') }}"></script>
    @endauth

    <script>
        // Toggle sidebar
        document.getElementById('menu-toggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('collapsed');
            document.querySelector('.main-content').classList.toggle('expanded');
        });

        // User dropdown
        document.querySelector('.user-menu').addEventListener('click', function(e) {
            if (!e.target.closest('.user-dropdown')) {
                this.classList.toggle('active');
            }
        });

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.user-menu')) {
                document.querySelector('.user-menu').classList.remove('active');
            }
        });
    </script>
</body>
</html>
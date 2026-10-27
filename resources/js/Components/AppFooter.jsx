import DevelopmentCredits from '@/Components/DevelopmentCredits';
import './AppFooter.css';

const COMPANY = 'شركة أبناء فارس أبو زايد للتجارة والاستيراد';
const COMPANY_EN = 'Sons of Fares AbuZayed Trading & Import Company';

export default function AppFooter({ compact = false }) {
    const year = new Date().getFullYear();

    if (compact) {
        return <footer className="af-login-footer" dir="rtl">
            <p>© <span dir="ltr">{year}</span> {COMPANY}</p>
            <DevelopmentCredits />
        </footer>;
    }

    return <footer className="af-footer" dir="rtl">
        <div className="af-footer__inner">
            <div className="af-footer__company">
                <img className="af-footer__logo" src="/images/logo-af.webp" alt="شعار الشركة" width="89" height="52" />
                <div className="af-footer__rights">
                    <p>© <span dir="ltr">{year}</span> جميع الحقوق محفوظة لدى <b>{COMPANY}</b></p>
                    <p className="af-footer__english" dir="ltr">© {year} {COMPANY_EN}. All rights reserved.</p>
                </div>
            </div>
            <DevelopmentCredits />
        </div>
        <p className="af-footer__wordmark" dir="ltr" aria-hidden="true">POWERCOLLECT</p>
    </footer>;
}

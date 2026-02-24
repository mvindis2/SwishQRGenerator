document.addEventListener('DOMContentLoaded', function() {
    const swishForm = document.getElementById('swish-form');
    if (!swishForm) return;

    swishForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const form = e.target;

        // Hämta värden från formuläret
        const data = {
            firstname: form.firstname.value,
            lastname: form.lastname.value,
            address: form.address.value,
            city: form.city.value,
            postal_code: form.postal_code.value,
            mobile: form.mobile.value,
            email: form.email.value,
            amount: form.amount.value,
            magazine: form.magazine ? (form.magazine.checked ? 'Ja' : 'Nej') : 'Nej'
        };

        // Hämta Swish-numret från PHP
        const swishNumber = swishQRData.swishNumber;

        // Kontrollera om användaren är på en mobil enhet
        const ua = navigator.userAgent.toLowerCase();
        const isIphone = ua.includes("iphone");
        const isAndroid = ua.includes("android");
        
        // Mobile enhet
        if (isIphone || isAndroid) {
            
            let swishData = {
                version: 1,
                payee: { value: swishNumber },
                message: { value: `${data.firstname} ${data.lastname}` }
            };
        
            // Inkludera summan på Android
            if (isAndroid) {
                swishData.amount = { value: data.amount };
            }
        
            const swishUrl = `swish://payment?data=${encodeURIComponent(JSON.stringify(swishData))}`;
            window.location.href = swishUrl;
        
        } else {
            // Desktop – show QR modal as before
            document.getElementById('qr-modal').style.display = 'flex';
        
            fetch(swishQRData.ajaxUrl + '?action=generate_swish_qr', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            })
            .then(res => {
                if (!res.ok) throw new Error("Fel vid generering");
                return res.blob();
            })
            .then(blob => {
                document.getElementById('qr-image').src = URL.createObjectURL(blob);
            })
            .catch(err => alert("Kunde inte generera QR-kod"));
        }

        //Skicka e-postnotifiering till admin
        fetch(swishQRData.ajaxUrl + '?action=send_admin_notification', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(result => {
            if (!result.success) {
                console.error('Kunde inte skicka admin notifiering');
            }
        })
        .catch(error => {
            console.error('Fel vid skickande av admin notifiering:', error);
        });
    });
}); 

<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="X-UA-Compatible" content="IE=edge" />
<meta name="color-scheme" content="light" />
<meta name="supported-color-schemes" content="light" />
<title>{{ $settings['brand_name'] }}{{ $settings['brand_suffix'] }}</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;700;800&display=swap');
body { margin: 0; padding: 0; width: 100% !important; -webkit-text-size-adjust: 100%; }
table { border-collapse: collapse; }
img { border: 0; line-height: 100%; outline: none; text-decoration: none; }
.te-content h1 { margin: 0 0 12px; color: {{ $settings['heading_color'] }}; font-family: 'Plus Jakarta Sans', Arial, sans-serif; font-size: 26px; font-weight: 800; line-height: 1.3; }
.te-content h2, .te-content h3 { color: {{ $settings['heading_color'] }}; font-family: 'Plus Jakarta Sans', Arial, sans-serif; font-weight: 800; }
.te-content p, .te-content li { color: {{ $settings['text_color'] }}; font-family: 'Plus Jakarta Sans', Arial, sans-serif; font-size: 15.5px; line-height: 1.65; }
.te-content a { color: {{ $settings['heading_color'] }}; font-weight: 700; }
.te-content .te-button { display: inline-block; padding: 14px 24px; color: #FFFFFF; background-color: {{ $settings['heading_color'] }}; border-radius: 12px; font-weight: 700; text-decoration: none; }
.te-content img { max-width: 100%; }
@media only screen and (max-width: 620px) {
.te-card-pad { padding: 32px 24px !important; }
.te-footer-pad { padding: 32px 24px !important; }
.te-content h1 { font-size: 22px !important; }
}
</style>
</head>
<body style="margin:0; padding:0; background-color:{{ $settings['background_color'] }};">
@if(filled($preheader))
<div style="display:none; font-size:1px; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden; mso-hide:all;">{{ $preheader }}&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;</div>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{{ $settings['background_color'] }};">
<tr><td align="center" style="padding:36px 16px 48px;">

<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px;">
<tr><td align="center" style="padding:0 0 28px;">
<a href="{{ $settings['website_url'] }}" style="display:inline-block;"><img src="{{ $settings['logo_url'] }}" width="164" alt="{{ $settings['brand_name'] }}{{ $settings['brand_suffix'] }}" style="display:block; width:164px; height:auto;" /></a>
</td></tr>
</table>

<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px;">
<tr><td height="6" style="height:6px; background-color:{{ $settings['accent_color'] }}; border-radius:20px 20px 0 0; font-size:0; line-height:0;">&nbsp;</td></tr>
<tr><td class="te-card-pad te-content" style="background-color:{{ $settings['card_color'] }}; border-radius:0 0 20px 20px; padding:44px 48px 40px; font-family:'Plus Jakarta Sans', -apple-system, 'Segoe UI', Arial, sans-serif;">
{!! $body !!}
</td></tr>
</table>

@if($settings['show_app_badges'])
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px;">
<tr><td height="26" style="height:26px; font-size:0; line-height:0;">&nbsp;</td></tr>
<tr><td align="center" style="padding:0 0 12px; color:{{ $settings['muted_color'] }}; font-family:'Plus Jakarta Sans', Arial, sans-serif; font-size:12.5px; font-weight:600;">{{ $settings['badge_caption'] }}</td></tr>
<tr><td align="center"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
<td><a href="{{ $settings['app_store_url'] }}"><img src="{{ $settings['app_store_badge_url'] }}" width="130" height="44" alt="Download on the App Store" style="display:block; width:130px; height:44px;" /></a></td>
<td width="12">&nbsp;</td>
<td><a href="{{ $settings['play_store_url'] }}"><img src="{{ $settings['play_store_badge_url'] }}" width="147" height="44" alt="Get it on Google Play" style="display:block; width:147px; height:44px;" /></a></td>
</tr></table></td></tr>
</table>
@endif

<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px;"><tr><td height="16">&nbsp;</td></tr></table>

<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px;">
<tr><td class="te-footer-pad" align="center" style="background-color:{{ $settings['footer_color'] }}; border-radius:20px; padding:34px 40px;">
<p style="margin:0 0 4px; color:#FFFFFF; font-family:'Plus Jakarta Sans', Arial, sans-serif; font-size:19px; font-weight:800;">{{ $settings['brand_name'] }}<span style="color:{{ $settings['accent_color'] }};">{{ $settings['brand_suffix'] }}</span></p>
<p style="margin:0 0 20px; color:#A9ACD0; font-family:'Plus Jakarta Sans', Arial, sans-serif; font-size:13px; font-weight:500;">{{ $settings['tagline'] }}</p>
<p style="margin:0 0 18px; font-family:'Plus Jakarta Sans', Arial, sans-serif; font-size:13px; font-weight:700;">
<a href="{{ $settings['website_url'] }}" style="color:#FFFFFF; text-decoration:none;">{{ parse_url($settings['website_url'], PHP_URL_HOST) ?: $settings['website_url'] }}</a>
<span style="color:#555887;">&nbsp;&middot;&nbsp;</span>
<a href="mailto:{{ $settings['support_email'] }}" style="color:#FFFFFF; text-decoration:none;">{{ $settings['support_email'] }}</a>
</p>

@if($settings['show_social_links'])
@php($socials = [
    ['url' => $settings['instagram_url'], 'icon' => 'https://mytherapist.ng/assets/images/email/soc-instagram.png', 'alt' => 'Instagram'],
    ['url' => $settings['youtube_url'], 'icon' => 'https://mytherapist.ng/assets/images/email/soc-youtube.png', 'alt' => 'YouTube'],
    ['url' => $settings['facebook_url'], 'icon' => 'https://mytherapist.ng/assets/images/email/soc-facebook.png', 'alt' => 'Facebook'],
    ['url' => $settings['tiktok_url'], 'icon' => 'https://mytherapist.ng/assets/images/email/soc-tiktok.png', 'alt' => 'TikTok'],
    ['url' => $settings['linkedin_url'], 'icon' => 'https://mytherapist.ng/assets/images/email/soc-linkedin.png', 'alt' => 'LinkedIn'],
    ['url' => $settings['x_url'], 'icon' => 'https://mytherapist.ng/assets/images/email/soc-x.png', 'alt' => 'X'],
])
<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
@foreach($socials as $social)
@if(filled($social['url']))
<td style="padding:0 5px 22px;"><a href="{{ $social['url'] }}"><img src="{{ $social['icon'] }}" width="32" height="32" alt="{{ $social['alt'] }}" style="display:block; width:32px; height:32px;" /></a></td>
@endif
@endforeach
</tr></table>
@endif

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td height="1" style="height:1px; background-color:{{ $settings['footer_divider_color'] }}; font-size:0;">&nbsp;</td></tr></table>
<p style="margin:20px 0 0; color:{{ $settings['muted_color'] }}; font-family:'Plus Jakarta Sans', Arial, sans-serif; font-size:11.5px; line-height:1.7;">
{{ $settings['footer_note'] }}<br />
{{ $settings['crisis_text'] }}<br /><br />
@if($unsubscribeUrl)<a href="{{ $unsubscribeUrl }}" style="color:#A9ACD0;">Unsubscribe</a><br /><br />@endif
&copy; {{ now()->year }} {{ $settings['company_line'] }}
</p>
</td></tr>
</table>

</td></tr>
</table>
</body>
</html>

# Oman FAQ chatbot (Alia): Batch 1, v2 (DRAFT for owner review)

Status: DRAFT. Not wired into anything. 53 Q&As across 10 categories.
v2 change: answers rewritten in Alia's warmer, more conversational voice (typically 3–5 sentences), with follow-up quick replies.
Remaining ~47 follow in Batch 2 after tone approval.

## How to read this file
- **Volatile = yes**: the answer can change (rules, prices, hours, dates). Alia appends: "Details can change, so please verify before you travel."
- **[CONTACT]** = Alia adds the contact options block (see "Standard responses").
- **Follow-ups** = the quick-reply buttons shown after the answer.
- Placeholders in {BRACES} are replaced with real details later.
- URLs marked (verify) must be checked before launch.
- Alia never states tour, hotel or activity prices.

---

## Standard responses (used everywhere)

### Greeting
"Hi there, I'm Alia, the virtual travel assistant at Travel Store Oman. I'm happy to answer questions about travelling in Oman, from the best time to visit to what to wear. What would you like to know?"

### Fallback: question not covered
"That's a good question, and I'd rather not guess and risk getting it wrong. Our team will know the answer and they're always happy to help. You can reach them here:
- Phone: {PHONE_LINK}
- WhatsApp: {WHATSAPP_LINK}
- Email: {EMAIL_LINK}
- Contact form: {FORM_LINK}"

### Price response (any question about tour, hotel or package prices)
"I'm afraid I can't share prices here, because they depend on your dates, the hotels you like and the size of your group. You can find the details on {TOUR_PAGE_LINK}, or our team can put together a proper quote for you:
- Phone: {PHONE_LINK}
- WhatsApp: {WHATSAPP_LINK}
- Email: {EMAIL_LINK}
- Contact form: {FORM_LINK}"

### Visa caveat (appended to every visa answer)
"Visa rules can change, so for the most up-to-date information please check the Royal Oman Police eVisa website (https://evisa.rop.gov.om, verify) or your nearest Omani embassy before you travel."

### Politics / government / conflict: decline (rotate these four)
1. "I appreciate you asking, but politics and current affairs aren't something I can talk about. I'm here for the travel side of things. If you're thinking about visiting Oman, I'd love to help with places to go, the best time to visit, or anything else for your trip."
2. "That's a bit outside my area, I'm afraid. I stick to travel topics and don't comment on politics or world events. If there's anything about planning a trip to Oman I can help with, just ask."
3. "I'm not the right person for that one. I keep to travel, and I don't discuss politics or government matters. What I can do is tell you about Oman's landscapes, culture and the practical side of visiting. Where would you like to start?"
4. "Thanks for asking, but I can't comment on political matters. I'm a travel assistant, so I'm happiest talking about trips, places and planning. Is there something about Oman I can help you with?"

### Travel-safety / "is it safe right now?" (neutral, no opinion)
"I understand wanting to feel sure before you travel. Oman is widely regarded as a welcoming destination with low crime levels, but conditions can change, and I'm not the best source for current advice. The most reliable step is to check your own government's official travel advice before you book. If you'd like to talk it through with a person, our team is happy to help:" + [CONTACT]

### Empathy openers (examples; editable)
- "I'm sorry about the trouble. Let's see how we can sort this out."
- "That sounds frustrating, and I understand. Let me point you to someone who can help properly."

---

## 1. Visa and entry

**Q001. Do I need a visa to visit Oman?**
It depends on your passport. Many nationalities can come to Oman for a short tourist stay without a visa, while others need to apply for an eVisa online before they travel. I can't confirm the rule for every passport, so the safest thing is to check the official portal for your nationality before you book. (Visa caveat appended.)
Volatile: yes
Follow-ups: [How do I apply for an eVisa?] [Passport validity]

**Q002. How do I apply for an Oman eVisa?**
Applications are made online through the Royal Oman Police eVisa portal. You'll usually need a copy of your passport, a recent photo and your travel details, and the portal shows the current fees and processing times as you go. It's worth applying a little ahead of your trip rather than leaving it to the last minute. (Visa caveat appended.)
Volatile: yes
Follow-ups: [Passport validity] [Do I need a visa?]

**Q003. How long should my passport be valid?**
Most travellers are asked to hold a passport that's valid for at least six months from the day they arrive. It's worth checking yours before you book, because an airline can refuse boarding if a passport doesn't meet the entry rules. If yours is close to the limit, renewing it first saves a lot of stress. (Visa caveat appended.)
Volatile: yes
Follow-ups: [Do I need a visa?] [How do I apply for an eVisa?]

**Q004. Can I get a visa on arrival?**
That depends on your nationality and on the rules in force when you travel, and they have changed over the years. I'd suggest not counting on getting one at the airport. Checking before you fly, and arranging an eVisa if you need one, is the easiest way to avoid a stressful arrival. (Visa caveat appended.)
Volatile: yes
Follow-ups: [How do I apply for an eVisa?] [Passport validity]

**Q005. Can I visit Oman from Dubai or the UAE?**
Yes, plenty of people combine the two. You can travel between the UAE and Oman by road, by bus or on a short flight, and the Musandam peninsula is a popular trip from Dubai. Entry rules and any visa requirement depend on your nationality and the crossing you use, so check those before you go, especially if you plan to drive a rental car across the border. (Visa caveat appended.)
Volatile: yes
Follow-ups: [What is there to do in Musandam?] [Do I need a visa?]

---

## 2. Best time and weather

**Q006. When is the best time to visit Oman?**
For most of the country, October to April is the sweet spot. Days are warm and sunny, evenings are pleasantly cool, and it's perfect for the desert, the mountains and the coast. The big exception is Salalah in the south, which turns green and misty in summer during the Khareef season, so it's lovely at a different time of year to the rest of Oman.
Volatile: no
Follow-ups: [What is the Khareef?] [How hot is summer?]

**Q007. How hot does Oman get in summer?**
From about June to September it gets properly hot. Muscat and the inland areas can pass 40°C, and the humidity on the coast can make it feel heavier. If you travel then, plan sightseeing for early morning and evening, take it easy in the middle of the day, or head to Salalah or the mountains where it's cooler.
Volatile: no
Follow-ups: [What is the Khareef?] [Is it cold in the mountains?]

**Q008. What is the Khareef in Salalah?**
The Khareef is the monsoon season in Dhofar, the region around Salalah, roughly from late June to early September. While the rest of Oman is baking, the hills turn green, there's mist and light drizzle, and waterfalls and streams come to life. It's very popular with visitors from across the Gulf, so it pays to plan ahead.
Volatile: no
Follow-ups: [Why visit Salalah?] [Best time to visit]

**Q009. Is it cold in the mountains?**
It can be, yes. Places like Jabal Akhdar and Jabal Shams are noticeably cooler than the coast, and winter nights up there can be properly chilly, sometimes close to freezing. It's worth packing a warm layer even if you're visiting in the sunny months.
Volatile: no
Follow-ups: [What are Jabal Akhdar and Jabal Shams?] [Best time to visit]

**Q010. Does it rain in Oman? Are wadis safe after rain?**
Rain is rare, but when it comes it can be heavy and sudden, and dry riverbeds (wadis) can flood very quickly, even if the rain fell far away. For safety, avoid wadis during or after rain and follow local guidance. If the weather looks uncertain, your guide or hotel will always know the best plan for the day.
Volatile: no
Follow-ups: [Can I swim in Wadi Shab?] [Best time to visit]

---

## 3. Money

**Q011. What currency is used in Oman?**
Oman uses the Omani rial (OMR), which is divided into 1,000 baisa. It's pegged to the US dollar, so the exchange rate is very stable, which makes budgeting easier. One thing that surprises visitors is that the rial is worth much more than a dollar or euro, so the numbers on price tags look small.
Volatile: no
Follow-ups: [Can I use credit cards?] [Do I need to tip?]

**Q012. Can I use credit cards? Are there ATMs?**
Yes, cards are widely accepted in hotels, restaurants and shops in the main towns, and ATMs are easy to find. It's still wise to carry some cash for souqs, small shops, roadside stalls and remote areas, where cards may not work.
Volatile: no
Follow-ups: [Do I need to tip?] [Can I bargain in the souqs?]

**Q013. Do I need to tip?**
Tipping is appreciated but not expected. If a restaurant hasn't already added a service charge, around 10% is a common gesture for good service. It's also kind to give a small tip to guides, drivers and hotel porters if you've had a great experience.
Volatile: no
Follow-ups: [What currency is used?] [Can I use credit cards?]

**Q014. Can I bargain in the souqs?**
Yes, gentle bargaining is part of the fun in traditional souqs, especially for souvenirs, silver and textiles. Keep it friendly and relaxed, and a smile goes a long way. In fixed-price shops and malls, prices are not negotiable.
Volatile: no
Follow-ups: [Do I need to tip?] [Must-see places in Muscat]

**Q015. Is Oman expensive?**
It depends a lot on how you travel. Oman is generally mid-range to high-end compared with some neighbouring destinations, but there are ways to suit different styles of trip. I can't give tour prices here, but our team would be glad to talk through what fits your plans. [CONTACT]
Volatile: yes
Follow-ups: [How many days do I need?] [How do I book?]

---

## 4. Getting around

**Q016. Which airports can I fly into?**
Most international visitors arrive at Muscat International Airport, which is modern and easy to use. Salalah also has international flights, and there are smaller domestic airports such as Khasab, Duqm and Sohar. Flight routes change from season to season, so it's best to check with your airline.
Volatile: yes
Follow-ups: [Muscat to Salalah distance] [Can I rent a car?]

**Q017. Can I rent a car and drive myself?**
Yes, and many visitors love it. The main roads are generally good, driving is on the right, and it's easy to explore between Muscat, Nizwa and the coast at your own pace. Rental age, licence and insurance rules differ between companies, so check the details when you book.
Volatile: yes
Follow-ups: [Do I need an international driving permit?] [Do I need a 4x4?]

**Q018. Do I need an international driving permit?**
It depends on where your licence was issued and on the rental company. An international driving permit is often recommended, and it's easier to arrange it at home than to find out at the rental desk that you needed one. Please check with the rental company before you travel.
Volatile: yes
Follow-ups: [Can I rent a car?] [Do I need a 4x4?]

**Q019. Do I need a 4x4?**
For main roads and city driving, an ordinary car is absolutely fine. A 4x4 is recommended for desert dunes, mountain tracks and some wadis. If you'd rather not drive off-road yourself, a guided tour is a good alternative, with a local driver who knows the terrain.
Volatile: no
Follow-ups: [What is Wahiba Sands like?] [Can I rent a car?]

**Q020. Are there taxis and ride-hailing apps?**
Yes, licensed taxis and ride-hailing apps operate in Muscat and other major towns. If there's no meter, agree the fare before you set off to avoid any surprises. Outside the main towns, taxis are harder to find, so it's best to arrange transport ahead.
Volatile: yes
Follow-ups: [Can I rent a car?] [Which airports can I fly into?]

**Q021. How far is Muscat from Nizwa, Wahiba Sands and Salalah?**
Roughly speaking: Muscat to Nizwa takes about 1.5 to 2 hours by road, Muscat to Wahiba Sands about 3 hours, and Muscat to Salalah is a long drive of around 11 to 12 hours. Most people fly to Salalah instead, which takes about 1.5 hours. Journey times depend on traffic and where exactly you're going.
Volatile: no
Follow-ups: [Why visit Nizwa?] [Why visit Salalah?]

---

## 5. Culture and etiquette

**Q022. What should I wear in Oman?**
Oman is relaxed and welcoming, but modest dress is appreciated in public. A good rule for both men and women is to cover shoulders and knees. Swimwear is fine at hotel pools and beaches, but cover up when you leave them, and lightweight, loose clothes are comfortable in the heat anyway.
Volatile: no
Follow-ups: [Can I visit mosques?] [Can I take photos of people?]

**Q023. Can non-Muslims visit mosques?**
Some mosques welcome visitors at set times, usually in the mornings, and the Sultan Qaboos Grand Mosque in Muscat is the best known. Women need a headscarf, and everyone needs covered arms and legs. Visiting hours can change, so it's worth checking before you go.
Volatile: yes
Follow-ups: [What should I wear?] [Must-see places in Muscat]

**Q024. Can I take photos of people?**
Please always ask permission first, especially when it comes to women. Most people are happy to say yes if you ask politely. It's also best to avoid photographing military sites and government buildings.
Volatile: no
Follow-ups: [Can I fly a drone?] [What should I wear?]

**Q025. Is alcohol available?**
Alcohol is served in licensed hotels and restaurants, but it isn't sold in ordinary shops, and drinking in public is not permitted. There's also zero tolerance for drinking and driving, so please never get behind the wheel after a drink.
Volatile: yes
Follow-ups: [What is Ramadan like for visitors?] [What should I wear?]

**Q026. What is Ramadan like for visitors?**
Ramadan is a special time to be in Oman. During daylight hours, eating, drinking and smoking in public are discouraged out of respect, and opening hours change. Hotels and some restaurants serve visitors discreetly, and the evenings are wonderful, with shared meals and a warm, festive feeling. It's worth planning your days around the different rhythm.
Volatile: yes
Follow-ups: [What is the weekend in Oman?] [What should I wear?]

**Q027. What is the weekend in Oman?**
The weekend is Friday and Saturday. Some shops close around the midday Friday prayers, and many reopen in the evening. It's good to keep this in mind when you're planning things like souq visits or museum days.
Volatile: yes
Follow-ups: [Why visit Nizwa?] [What is Ramadan like?]

**Q028. How do people greet each other?**
A warm "As-salaamu alaykum" (peace be upon you) is the common greeting, and you'll hear it everywhere. Handshakes between men are normal. A man should wait for a woman to offer her hand first, and a woman can simply greet with a smile and a nod. Locals really appreciate visitors who try a few words of Arabic.
Volatile: no
Follow-ups: [Public affection] [What should I wear?]

**Q029. Is public affection okay?**
It's best to keep public displays of affection discreet. Holding hands as a married couple is generally fine, but anything more than that isn't appropriate in public. Oman is a conservative country, and a respectful attitude is always appreciated.
Volatile: no
Follow-ups: [What should I wear?] [How do people greet each other?]

---

## 6. Food and drink

**Q030. What are typical Omani dishes?**
Omani food is full of gentle spices. Try shuwa, which is marinated meat slow-cooked for hours, majboos (spiced rice with meat or fish), harees, fresh seafood and mishkak, which are grilled skewers. Finish with Omani halwa and a cup of kahwa, the cardamom coffee, served with dates. It's a lovely way to experience the culture.
Volatile: no
Follow-ups: [Vegetarian food] [Is tap water safe?]

**Q031. Is it easy to find vegetarian food?**
Yes, in most hotels and restaurants. Hummus, salads, vegetable curries, falafel, lentil dishes and rice dishes are all easy to find. Just let the restaurant know about any dietary needs, and they're usually happy to help.
Volatile: no
Follow-ups: [Typical Omani dishes] [Is tap water safe?]

**Q032. Is tap water safe to drink?**
Tap water is treated, but most visitors and locals prefer bottled water, which is cheap and easy to find everywhere. In the heat, keep a bottle with you, especially when you're out in the desert or hiking.
Volatile: no
Follow-ups: [Typical Omani dishes] [How hot is summer?]

---

## 7. Practical tips

**Q033. What languages are spoken?**
Arabic is the official language. English is widely spoken in hotels, restaurants and tourist areas, so you'll manage easily. Learning a few words like "shukran" (thank you) is always appreciated.
Volatile: no
Follow-ups: [How do people greet each other?] [SIM card and internet]

**Q034. What plugs and voltage does Oman use?**
Oman uses 230V at 50Hz, with UK-style three-pin sockets (Type G). If your plugs are different, a travel adapter will do the job. Most modern phone and laptop chargers cope with the voltage, but check the label on yours to be sure.
Volatile: no
Follow-ups: [SIM card and internet] [Time zone]

**Q035. What time zone is Oman in?**
Oman is on Gulf Standard Time (UTC+4). It doesn't use daylight saving time, so the time stays the same all year round.
Volatile: no
Follow-ups: [What is the weekend?] [Which airports can I fly into?]

**Q036. How can I get a SIM card or internet?**
Local operators such as Omantel and Ooredoo sell tourist SIM cards, including at the airport, and it's an easy way to get data for maps and messaging. Wi-Fi is also common in hotels and cafés. Offers and requirements change, so check what's available when you arrive.
Volatile: yes
Follow-ups: [Plugs and voltage] [What languages are spoken?]

**Q037. Do I need travel insurance or vaccinations?**
Travel insurance is strongly recommended for any trip. Vaccination requirements depend on where you're travelling from and can change, so please check with your doctor or the official health guidance in your country before you go.
Volatile: yes
Follow-ups: [Is tap water safe?] [Is Oman safe for tourists?]

**Q038. Can I fly a drone in Oman?**
Drones generally need official permission from the Civil Aviation Authority before you can use them, and rules can be strict. Please check the current requirements before bringing one, as drones can be held at the airport if you arrive without the right permission.
Volatile: yes
Follow-ups: [Can I take photos of people?] [Entry and visa]

---

## 8. Places and experiences

**Q039. What are the must-see places in Muscat?**
Some favourites are the Sultan Qaboos Grand Mosque, Mutrah Souq and the Corniche, the Royal Opera House, Al Alam Palace (admired from outside) and the National Museum. Mutrah Souq is great in the evening when it's cooler and the lights come on. Muscat is relaxed and spread out, so a car or taxi helps.
Volatile: no
Follow-ups: [Can I visit mosques?] [Why visit Nizwa?]

**Q040. Why visit Nizwa?**
Nizwa is Oman's old heart. It has a historic fort, a traditional souq, and the famous Friday-morning livestock market, which is a real highlight if your timing works out. It's also a good base for exploring the mountains and old villages nearby. Market days and opening hours can change, so check before you go.
Volatile: yes
Follow-ups: [What are Jabal Akhdar and Jabal Shams?] [Muscat to Nizwa distance]

**Q041. What is Wahiba (Sharqiya) Sands like?**
It's a sea of golden dunes, and it's one of the most memorable places in Oman. Visitors come for dune drives, sunset walks and a night in a desert camp under a sky full of stars. Camel rides and Bedouin hospitality are part of the experience.
Volatile: no
Follow-ups: [Do I need a 4x4?] [Best time to visit]

**Q042. Can I swim in Wadi Shab?**
Yes, Wadi Shab is known for its beautiful turquoise pools. Getting there involves a short boat crossing and a walk, so wear sturdy shoes, bring modest swimwear and water, and go early to avoid the heat and crowds. Please avoid it after rain, as wadis can flood.
Volatile: yes
Follow-ups: [Does it rain? Are wadis safe?] [What should I wear?]

**Q043. What is there to do in Musandam?**
Musandam is famous for its dramatic fjord-like coastline, sometimes called the "Norway of Arabia". Most visitors take a dhow cruise from Khasab, with the chance of spotting dolphins, plus swimming and snorkelling stops. It's a peaceful, beautiful contrast to the rest of Oman.
Volatile: no
Follow-ups: [Can I visit Oman from Dubai?] [Do I need a visa?]

**Q044. Why visit Salalah?**
Salalah is known for its frankincense heritage, long beaches and, during the Khareef season, green hills and waterfalls. Nearby highlights include Wadi Darbat and the Mughsail blowholes. It feels quite different from northern Oman, which makes it a lovely addition to a longer trip.
Volatile: no
Follow-ups: [What is the Khareef?] [Muscat to Salalah distance]

**Q045. What are Jabal Akhdar and Jabal Shams?**
They are mountain areas in the Hajar range. Jabal Akhdar has terraced villages, rose and fruit orchards and cool air, while Jabal Shams has the canyon often called the "Grand Canyon of Oman". A 4x4 is needed on some mountain roads, and it's much cooler up there, so bring layers.
Volatile: no
Follow-ups: [Is it cold in the mountains?] [Do I need a 4x4?]

**Q046. Can I see turtles in Oman?**
Yes, Ras al Jinz is well known for turtle watching, and green turtles nest there for much of the year. Visits are guided and regulated to protect the turtles, so it's wise to book ahead and follow the guide's instructions. Timings can vary, so check the latest details before you plan around it.
Volatile: yes
Follow-ups: [Best time to visit] [How do I book?]

---

## 9. Families and solo travellers

**Q047. Is Oman good for families with children?**
Yes, Oman is very family friendly. There are beaches, wadis, forts, desert camps and a relaxed, welcoming attitude towards children. The main thing to plan around is the heat in summer, so keep outdoor activities for early or late in the day.
Volatile: no
Follow-ups: [Best time to visit] [Do you arrange custom itineraries?]

**Q048. Is Oman suitable for solo women travellers?**
Many solo women travellers enjoy Oman and find the people welcoming and respectful. Dressing modestly and using normal travel common sense, like you would anywhere, is advised. If you'd like a trip where the logistics are handled for you, our team can help.
Volatile: no
Follow-ups: [What should I wear?] [Do you arrange custom itineraries?]

**Q049. Is Oman safe for tourists?**
(Use the standard travel-safety response above.) [CONTACT]
Volatile: yes
Follow-ups: [Insurance and vaccinations] [Do you arrange custom itineraries?]

---

## 10. Tours and booking

**Q050. How many days do I need in Oman?**
About 7 to 10 days lets you see Muscat, the mountains, the desert and the coast without rushing. If you have less time, a shorter trip can focus on Muscat and one or two regions, and it's better to see a little well than a lot in a blur. Tell me what you love doing, and I can suggest what to focus on.
Volatile: no
Follow-ups: [Do you arrange custom itineraries?] [Best time to visit]

**Q051. Do you arrange tours and custom itineraries?**
Yes, our team arranges tours and tailor-made itineraries. Tell them your dates, interests and group size, and they'll suggest a plan that fits you. It's the easiest way to get a trip that feels right for you. [CONTACT]
Volatile: no
Follow-ups: [How do I book?] [How many days do I need?]

**Q052. How much does a tour cost?**
(Use the standard price response above.)
Volatile: yes
Follow-ups: [Do you arrange custom itineraries?] [How do I book?]

**Q053. How do I book?**
Our team will guide you through booking from start to finish, so the easiest way is to get in touch and tell them about your plans. They'll answer your questions along the way. [CONTACT]
Volatile: no
Follow-ups: [Do you arrange custom itineraries?] [How many days do I need?]

---

## Notes for the reviewer
- Counts: 53 entries; Batch 2 needs ~47 more to reach about 100.
- Topics planned for Batch 2: public holidays and National Day, shopping and souvenirs (frankincense, silver, khanjar), Bahla and Jabrin forts, Sur and Ras al Hadd, Bimmah Sinkhole, Wadi Bani Khalid, Duqm, Sohar, accessibility, medical care, business hours, camping, bird and whale watching, diving and snorkelling, golf, photography spots, packing list.
- Items to verify before launch: eVisa URL, mosque visiting hours, SIM operators, drone rules, passport-validity rule, the Friday market and turtle-visit details, and "Norway of Arabia" wording if you prefer not to use it.
- Alia never mentions prices. Q015, Q052 and the price response all hand off to the tour page or the team.

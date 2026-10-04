from pathlib import Path
base=Path('outputs/use-case-narratives/build.py').read_text(encoding='utf-8-sig')
exec(base[:base.index('cases = [')])
cases=[{
'name':'Administrator Dashboard','actor':'Administrator',
'goal':'Provide a community-wide overview of senior records, registrations, assistance requests, schedules, and recorded activity.',
'pre':['The administrator is logged in with an Active account.','The account has the Administrator role.'],
'post':['The administrator views current community-wide summaries and previews.','Selected dashboard links open the corresponding management pages.'],
'flow':[
('The administrator opens Dashboard.','The system verifies access and displays the date and a personalized greeting.'),
('The administrator reviews the summary cards.','The system shows total senior citizens, pending registrations, requests in review (Pending plus Under Review), and completed requests.'),
('The administrator examines request activity.','The system displays daily submissions for the last seven days and a breakdown of Pending, Under Review, Approved, Scheduled, Completed, and Rejected requests.'),
('The administrator reviews recent requests and schedules.','The system shows up to four latest requests and three upcoming scheduled requests from today onward, with links to their details.'),
('The administrator reviews Recent activity.','The system displays up to four latest audit entries, including the action, user, and date/time.'),
('The administrator selects a card, request, schedule, or shortcut.','The system opens the relevant senior records, registrations, requests, reports, application details, or audit log page.')],
'alt':[
('2A: No matching records','The corresponding summary card displays zero.'),
('3A: No requests in the last seven days','The chart displays zero daily counts. If there are no requests overall, the breakdown also shows zero.'),
('4A: No requests or upcoming appointments','The relevant panel displays an empty-state message.'),
('5A: No recorded activity','The system explains that recorded staff and administrator activity will appear here.')],
'exc':[
('User is not logged in','The system redirects to Login.'),
('Account is inactive or has another role','The system blocks access to the administrator dashboard. Pending or Rejected senior accounts are redirected to application tracking.')]
}, {
'name':'Staff Dashboard','actor':'Barangay Staff',
'goal':'Help barangay staff monitor new requests, review workload, registrations, appointments, and community announcements.',
'pre':['The staff member is logged in with an Active account.','The account has the Barangay Staff role.'],
'post':['The staff member views current request and appointment summaries.','Selected links open the relevant staff workflow pages.'],
'flow':[
('The staff member opens Dashboard.','The system verifies access and displays the date and a personalized greeting.'),
('The staff member reviews the summary cards.','The system shows requests submitted today, requests awaiting review (Pending plus Under Review), all appointments scheduled today, and pending registrations.'),
('The staff member examines request activity.','The system displays daily request submissions for the last seven days and a breakdown of the six request statuses.'),
('The staff member reviews requests and schedules.','The system shows up to four latest requests and three upcoming scheduled requests from today onward, with links to their details.'),
('The staff member reads Community announcements.','The system shows up to three latest announcements for All or Senior Citizen audiences. Emergency announcements are marked Important announcement.'),
('The staff member selects a request, schedule, announcement, or shortcut.','The system opens the related application, announcements, request queue, registration review, or reports page.')],
'alt':[
('2A: No matching records','The corresponding summary card displays zero.'),
('3A: No recent request submissions','The chart displays zero counts for days without submissions.'),
('4A: No requests or upcoming schedules','The system displays the relevant empty-state message.'),
('5A: No announcements','The system displays: "No announcements yet. Check back for community updates."')],
'exc':[
('User is not logged in','The system redirects to Login.'),
('Account is inactive or has another role','The system blocks access to the staff dashboard. Pending or Rejected senior accounts are redirected to application tracking.')]
}, {
'name':'Senior Dashboard','actor':'Senior Citizen',
'goal':'Allow senior citizens to view their own assistance progress, account status, appointments, announcements, and notifications.',
'pre':['The senior citizen is logged in with an Active account.','The account has the Senior Citizen role.'],
'post':['The senior views request and schedule information limited to their own account.','The senior can open assistance, registration tracking, announcements, and notification pages.'],
'flow':[
('The senior citizen opens Dashboard.','The system verifies access and displays the date, a personalized greeting, and the account status.'),
('The senior reviews the summary cards.','The system shows their total requests, requests in review (Pending plus Under Review), completed requests, and unread updates.'),
('The senior reviews Your recent requests and Your next schedule.','The system shows up to four of their latest requests and three of their upcoming scheduled requests from today onward.'),
('The senior reads Community announcements.','The system shows up to three latest announcements for All or Senior Citizen audiences and marks emergency announcements as important.'),
('The senior reviews Your updates.','The system shows up to three latest account notifications, read/unread indicators, and the unread count.'),
('The senior selects a request, appointment, update, or shortcut.','The system opens the related details or destination, assistance requests, registration tracking, announcements, or notifications page.')],
'alt':[
('2A: No matching requests or unread updates','The corresponding summary card displays zero.'),
('3A: No assistance requests','The system invites the senior to submit a first assistance request.'),
('3B: No upcoming appointments','The system explains that details will appear when assistance is scheduled.'),
('4A: No announcements','The system displays a message inviting the senior to check back for updates.'),
('5A: No notifications','The system explains that new account updates will appear here.')],
'exc':[
('User is not logged in','The system redirects to Login.'),
('Senior account is Pending or Rejected','The system redirects to application tracking instead of the dashboard.'),
('Account is inactive or has another role','The system denies access to the senior dashboard.')]
}]
layout=base[base.index('doc=Document()'):]
layout=layout.replace('enumerate(cases,1)','enumerate(cases,5)').replace('if n>1:','if n>5:').replace('Senior_Citizen_Use_Case_Narratives.docx','Dashboard_Use_Case_Narratives.docx')
exec(layout)
for root in [doc._element,doc.styles.element]:
 for node in list(root.iter(qn('w:pBdr'))):node.getparent().remove(node)
for t in doc.tables:
 for row in t.rows:
  for c in row.cells:
   if c.text in ['MAIN FLOW','Actor Action','System Response','ALTERNATIVE FLOW OF EVENTS','EXCEPTIONS']:
    for p in c.paragraphs:
     for r in p.runs:r.bold=True;r.font.cs_bold=True
doc.save(path)

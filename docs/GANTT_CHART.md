# Development Gantt Chart

This schedule covers the development of the **Senior Citizen Assistance System for Barangay Services**. It assumes a 12-week implementation period, with testing and documentation running alongside feature development.

```mermaid
gantt
    title Senior Citizen Assistance System — 12-Week Development Plan
    dateFormat  YYYY-MM-DD
    axisFormat  %b %d

    section Planning and Design
    Requirements gathering and scope definition       :done, p1, 2026-09-07, 5d
    Process mapping and database design               :p2, after p1, 5d
    Interface wireframes and project setup            :p3, after p1, 10d

    section Core System
    Authentication and role-based access              :c1, after p2, 7d
    Senior registration and profile management        :c2, after c1, 7d
    Administrator registration review and ID review   :c3, after c2, 5d

    section Service Features
    Assistance request workflow                       :s1, after c2, 7d
    Announcements and events management               :s2, after c1, 7d
    Senior citizen portal and notifications           :s3, after s1, 7d
    SMS gateway integration and SMS logs              :s4, after s1, 5d

    section Administration and Quality
    Reports, audit logs, settings, and backups        :a1, after s3, 7d
    Security hardening and access-control review      :a2, after s4, 5d
    Integration and user-acceptance testing           :q1, after a1, 7d
    Bug fixes and performance tuning                  :q2, after q1, 5d
    Documentation, deployment, and staff orientation  :q3, after q2, 5d
```

| Week | Main deliverables |
| --- | --- |
| 1 | Requirements, user roles, scope, and development environment |
| 2 | Database schema, workflow design, and interface wireframes |
| 3 | Login, registration foundation, sessions, and role authorization |
| 4 | Senior profile management and administrator registration approval |
| 5 | Assistance-request submission, processing, scheduling, and status tracking |
| 6 | Announcements, events, dashboards, and senior-facing views |
| 7 | Notifications, SMS gateway integration, and delivery logs |
| 8 | Reports, user management, audit logs, settings, and backup workflow |
| 9 | Security review: CSRF, validation, authorization, uploads, and error handling |
| 10 | End-to-end integration testing and user-acceptance testing |
| 11 | Defect resolution, usability improvements, and performance checks |
| 12 | User guide, technical documentation, deployment, and staff orientation |

## Milestones

1. **End of Week 2:** Approved database design and user-interface plan.
2. **End of Week 4:** Working registration, login, role access, and senior records.
3. **End of Week 7:** Core barangay services and SMS notification workflow complete.
4. **End of Week 9:** Administrative tools and security review complete.
5. **End of Week 12:** Tested system deployed with documentation and trained users.
